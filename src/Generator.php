<?php

declare(strict_types=1);

namespace Xsd2Php;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Parses a pool of XSD files with DOMDocument/DOMXPath and emits readonly,
 * constructor-promoted PHP classes with native types and backed enums for XSD
 * enumerations. Property attributes (e.g. for XML (de)serialization) are
 * delegated to Config::$attributeStrategy.
 *
 * Known limitations (not every XSD construct maps cleanly to a typed PHP class):
 * - xs:include/xs:import inside the XSD files themselves are not followed; every
 *   contributing file must be listed in Config::$xsdPaths explicitly.
 * - xs:any (wildcard content) has no typed equivalent, falls back to string.
 * - xs:union and xs:list simpleTypes fall back to a plain string.
 * - substitutionGroup and abstract complexTypes (schema-level polymorphism)
 *   are not supported; use one of the other type kinds instead.
 */
final class Generator
{
    private const string XS_NS = 'http://www.w3.org/2001/XMLSchema';

    private static function stringFallback(): TypeInfo
    {
        return new TypeInfo(kind: TypeKind::Scalar, phpType: 'string');
    }

    /** @var array<string, \DOMElement> keyed by "{namespaceURI}#{localName}" */
    private array $complexTypes = [];
    /** @var array<string, \DOMElement> */
    private array $simpleTypes = [];
    /** @var array<string, \DOMElement> */
    private array $attributeGroups = [];
    /** @var array<string, \DOMElement> */
    private array $groups = [];
    /** @var array<string, \DOMElement> all global top-level xs:element declarations */
    private array $elements = [];

    /** @var array<string, string> fqcn cache, keyed by "{namespaceURI}#{localName}" */
    private array $generatedComplex = [];
    /** @var array<string, TypeInfo> */
    private array $resolvedSimple = [];
    private int $written = 0;
    /** @var array<string, true> dirs mkdir() has already run for, avoids a redundant syscall per file */
    private array $createdDirs = [];

    /** @var array<string, list<Property>> resolveBaseProperties() result cache, keyed by "{namespaceURI}#{localName}" */
    private array $basePropertiesCache = [];
    /** @var array<string, true> base keys currently being resolved, detects complexContent/extension cycles */
    private array $basePropertiesInProgress = [];

    /** @var array<string, array{0: \DOMElement, 1: ?\DOMElement}[]> collectGroupRefElements() result cache, keyed by group key */
    private array $groupElementsCache = [];
    /** @var array<string, \DOMElement[]> collectAttributes() result cache for attributeGroup refs, keyed by attributeGroup key */
    private array $attributeGroupCache = [];

    /** @var \WeakMap<\DOMDocument, \DOMXPath> */
    private \WeakMap $xpathCache;

    private readonly Filesystem $filesystem;

    public function __construct(private readonly Config $config)
    {
        $this->xpathCache = new \WeakMap();
        $this->filesystem = new Filesystem();
    }

    /** Wipes every configured output directory, regenerates everything, returns the number of files written. */
    public function generate(): int
    {
        foreach ($this->config->namespaceMap as $mapping) {
            $this->filesystem->remove($mapping->outputDir);
        }
        $this->indexSchemas();

        foreach (array_keys($this->complexTypes) as $key) {
            $this->ensureComplexClass($key);
        }
        foreach (array_keys($this->simpleTypes) as $key) {
            $this->resolveSimpleTypeRef($key);
        }

        foreach ($this->elements as $key => $el) {
            [$xsdNs, $local] = explode('#', $key, 2);
            $namespace = $this->namespaceFor($xsdNs)->phpNamespace;
            $xp = $this->xpath($this->ownerDocOf($el));
            $inlineComplex = $this->query($xp, 'xs:complexType', $el)->item(0);
            if ($inlineComplex instanceof \DOMElement) {
                $this->buildComplexClass($inlineComplex, Naming::toClassName($local), $namespace);
            } elseif ('' !== $el->getAttribute('type')) {
                $typeInfo = $this->resolveParticleType($el, Naming::toClassName($local), $namespace);
                $this->note("root element '{$key}' aliases {$typeInfo->phpType}");
            }
        }

        return $this->written;
    }

    private function namespaceFor(string $xsdNamespaceUri): NamespaceMapping
    {
        return $this->config->namespaceMap[$xsdNamespaceUri]
            ?? throw new \RuntimeException("No PHP namespace mapped for XSD namespace '{$xsdNamespaceUri}'");
    }

    private function xpath(\DOMDocument $doc): \DOMXPath
    {
        if (!isset($this->xpathCache[$doc])) {
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('xs', self::XS_NS);
            $this->xpathCache[$doc] = $xp;
        }

        return $this->xpathCache[$doc];
    }

    /** Every \DOMElement parsed from a loaded document has a non-null ownerDocument - a truly unreachable defensive check, not a malformed-schema case. */
    private function ownerDocOf(\DOMElement $node): \DOMDocument
    {
        return $node->ownerDocument ?? throw new \RuntimeException('DOMElement without ownerDocument (detached node)');
    }

    /**
     * Wraps DOMXPath::query(), which is declared |false for a malformed XPath expression - unreachable for this generator's own static, hardcoded expressions.
     *
     * @return \DOMNodeList<\DOMNameSpaceNode|\DOMNode>
     */
    private function query(\DOMXPath $xp, string $expression, ?\DOMNode $context = null): \DOMNodeList
    {
        $result = $xp->query($expression, $context);
        if (false === $result) {
            throw new \RuntimeException("Invalid XPath expression '{$expression}'");
        }

        return $result;
    }

    /** @var array<string, string> xs:* localName => target Generator property, for indexSchemas()'s single combined query */
    private const array SCHEMA_NAME_BUCKETS = [
        'complexType' => 'complexTypes',
        'simpleType' => 'simpleTypes',
        'attributeGroup' => 'attributeGroups',
        'group' => 'groups',
        'element' => 'elements',
    ];

    private function indexSchemas(): void
    {
        $query = implode(' | ', array_map(
            static fn (string $localName): string => "/xs:schema/xs:{$localName}[@name]",
            array_keys(self::SCHEMA_NAME_BUCKETS),
        ));

        foreach ($this->config->xsdPaths as $file) {
            $dom = new \DOMDocument();
            $dom->load($file);
            $xp = $this->xpath($dom);
            $targetNs = $dom->documentElement?->getAttribute('targetNamespace') ?? '';

            foreach ($this->query($xp, $query) as $node) {
                if (!$node instanceof \DOMElement || !isset(self::SCHEMA_NAME_BUCKETS[$node->localName])) {
                    $this->warn('non-element or unrecognized node from indexSchemas() query, skipping');
                    continue;
                }
                $property = self::SCHEMA_NAME_BUCKETS[$node->localName];
                $key = $targetNs.'#'.$node->getAttribute('name');
                match ($property) {
                    'complexTypes' => $this->complexTypes[$key] = $node,
                    'simpleTypes' => $this->simpleTypes[$key] = $node,
                    'elements' => $this->elements[$key] = $node,
                    'groups' => $this->groups[$key] = $node,
                    'attributeGroups' => $this->attributeGroups[$key] = $node,
                };
            }
        }
    }

    /**
     * Resolves a possibly-prefixed QName against $contextNode's in-scope namespace bindings.
     *
     * @return array{0: string, 1: string} [namespaceURI, localName]
     */
    private function resolveQName(\DOMElement $contextNode, string $qname): array
    {
        [$prefix, $local] = Naming::splitQName($qname);

        return [$contextNode->lookupNamespaceURI($prefix) ?? '', $local];
    }

    private function warn(string $message): void
    {
        fwrite(\STDERR, "WARN: {$message}\n");
    }

    private function note(string $message): void
    {
        fwrite(\STDERR, "NOTE: {$message}\n");
    }

    /**
     * @param array<string, true> &$seenGroups
     *
     * @return array{0: \DOMElement, 1: ?\DOMElement}[] [element, enclosing xs:choice particle] pairs,
     *                                                  xs:sequence/xs:choice/xs:all nesting flattened, xs:group refs inlined. The enclosing choice
     *                                                  is the innermost xs:choice ancestor within this particle tree (null if the element sits
     *                                                  under xs:sequence/xs:all only) - callers use it to treat choice-branch elements as mutually
     *                                                  exclusive alternatives (nullable, "exactly one of" constraint) instead of independently
     *                                                  required siblings, which xs:sequence-style flattening alone would wrongly imply.
     */
    private function collectParticleElements(\DOMElement $particle, array &$seenGroups = [], ?\DOMElement $enclosingChoice = null): array
    {
        $xp = $this->xpath($this->ownerDocOf($particle));
        $ownChoice = 'choice' === $particle->localName ? $particle : $enclosingChoice;
        $elements = [];
        foreach ($this->query($xp, 'xs:element | xs:sequence | xs:choice | xs:all | xs:group', $particle) as $child) {
            if (!$child instanceof \DOMElement) {
                $this->warn('non-element node in particle content, skipping');
                continue;
            }
            if ('element' === $child->localName) {
                $elements[] = [$child, $ownChoice];
                continue;
            }
            if ('group' === $child->localName) {
                foreach ($this->collectGroupRefElements($child, $seenGroups) as [$el, $intrinsicChoice]) {
                    $elements[] = [$el, $intrinsicChoice ?? $ownChoice];
                }
                continue;
            }
            $elements = [...$elements, ...$this->collectParticleElements($child, $seenGroups, $ownChoice)];
        }

        return $elements;
    }

    /**
     * Resolves a ref="..." attribute (xs:group and xs:attributeGroup share this exact shape:
     * a named registry lookup with cycle detection via $seen, threaded by reference across one
     * particle/attribute walk) so empty-ref/circular-ref/unknown-ref diagnostics can't drift
     * between the two ref kinds the way they previously did (attributeGroup silently swallowed
     * the first two cases instead of warning).
     *
     * @param array<string, \DOMElement> $registry
     * @param array<string, true>        $seen
     *
     * @return array{0: string, 1: ?\DOMElement} [key, resolved node - null if unresolved]
     */
    private function resolveNamedRef(\DOMElement $refNode, array $registry, array &$seen, string $kindLabel): array
    {
        $refAttr = $refNode->getAttribute('ref');
        if ('' === $refAttr) {
            $this->warn("inline xs:{$kindLabel} definition (no ref=) is not supported, skipping");

            return ['', null];
        }

        [$ns, $local] = $this->resolveQName($refNode, $refAttr);
        $key = $ns.'#'.$local;

        if (isset($seen[$key])) {
            $this->warn("circular xs:{$kindLabel} ref involving '{$local}', stopping");

            return [$key, null];
        }
        if (!isset($registry[$key])) {
            $this->warn("unknown {$kindLabel} ref '{$local}'");

            return [$key, null];
        }

        $seen[$key] = true;

        return [$key, $registry[$key]];
    }

    /**
     * @param array<string, true> &$seenGroups
     *
     * @return array{0: \DOMElement, 1: ?\DOMElement}[]
     */
    private function collectGroupRefElements(\DOMElement $groupRef, array &$seenGroups): array
    {
        [$key, $group] = $this->resolveNamedRef($groupRef, $this->groups, $seenGroups, 'group');
        if (!$group instanceof \DOMElement) {
            return [];
        }
        if (isset($this->groupElementsCache[$key])) {
            return $this->groupElementsCache[$key];
        }

        $xp = $this->xpath($this->ownerDocOf($group));
        $groupParticle = $this->query($xp, 'xs:sequence | xs:choice | xs:all', $group)->item(0);
        $result = $groupParticle instanceof \DOMElement ? $this->collectParticleElements($groupParticle, $seenGroups) : [];

        return $this->groupElementsCache[$key] = $result;
    }

    /**
     * @param array<string, true> &$seenGroups
     *
     * @return \DOMElement[] xs:attribute nodes, attributeGroup refs resolved recursively
     */
    private function collectAttributes(\DOMElement $container, array &$seenGroups = []): array
    {
        $xp = $this->xpath($this->ownerDocOf($container));

        $attrs = [];
        foreach ($this->query($xp, 'xs:attribute', $container) as $attr) {
            if (!$attr instanceof \DOMElement) {
                $this->warn('non-element xs:attribute node, skipping');
                continue;
            }
            $attrs[] = $attr;
        }
        foreach ($this->query($xp, 'xs:attributeGroup', $container) as $ref) {
            if (!$ref instanceof \DOMElement) {
                $this->warn('non-element xs:attributeGroup node, skipping');
                continue;
            }
            [$key, $attributeGroup] = $this->resolveNamedRef($ref, $this->attributeGroups, $seenGroups, 'attributeGroup');
            if (!$attributeGroup instanceof \DOMElement) {
                continue;
            }
            if (isset($this->attributeGroupCache[$key])) {
                $attrs = [...$attrs, ...$this->attributeGroupCache[$key]];
                continue;
            }
            $resolved = $this->collectAttributes($attributeGroup, $seenGroups);
            $this->attributeGroupCache[$key] = $resolved;
            $attrs = [...$attrs, ...$resolved];
        }

        return $attrs;
    }

    /** Resolves a named simpleType to either a backed enum or a scalar PHP type. */
    private function resolveSimpleTypeRef(string $key): TypeInfo
    {
        if (isset($this->resolvedSimple[$key])) {
            return $this->resolvedSimple[$key];
        }
        // break self-reference cycles defensively
        $this->resolvedSimple[$key] = self::stringFallback();

        if (!isset($this->simpleTypes[$key])) {
            $this->warn("unknown simpleType '{$key}', falling back to string");

            return $this->resolvedSimple[$key];
        }

        $node = $this->simpleTypes[$key];
        $xp = $this->xpath($this->ownerDocOf($node));

        // xs:list/xs:restriction/xs:union are mutually exclusive per the XSD schema-for-schema -
        // a simpleType has at most one of them, so a combined query is unambiguous.
        $listOrRestriction = $this->query($xp, 'xs:list | xs:restriction', $node)->item(0);
        if ($listOrRestriction instanceof \DOMElement && 'list' === $listOrRestriction->localName) {
            $this->note("simpleType '{$key}' is xs:list, mapped to plain string");
            $this->resolvedSimple[$key] = self::stringFallback();

            return self::stringFallback();
        }
        if (!$listOrRestriction instanceof \DOMElement) {
            return $this->resolvedSimple[$key] = $this->fallbackScalar("simpleType '{$key}' is xs:union or has an unsupported restriction, mapped to plain string");
        }
        $restriction = $listOrRestriction;

        $baseInfo = $this->resolvePrimitiveOrNamedSimpleType($restriction, $restriction->getAttribute('base'));

        /** @var \DOMNodeList<\DOMElement> $enumerations */
        $enumerations = $this->query($xp, 'xs:enumeration', $restriction);
        if ($enumerations->length > 0 && TypeKind::Scalar === $baseInfo->kind) {
            [$xsdNs, $local] = explode('#', $key, 2);
            $result = $this->toEnumResult($local, $enumerations, $baseInfo->phpType, $this->namespaceFor($xsdNs)->phpNamespace);
            $this->resolvedSimple[$key] = $result;

            return $result;
        }

        // merge, not overwrite: XSD restriction is cumulative - a chain of named simpleTypes each
        // restricting the previous keeps every ancestor's facets, narrowed further by whatever
        // this level itself adds. This level's facets win on a key collision (e.g. a tighter
        // maxLength further down the chain).
        $baseInfo = $this->mergeFacets($baseInfo, $restriction);
        if (TypeKind::Scalar === $baseInfo->kind) {
            // the type as directly referenced (not an ancestor further up a restriction chain,
            // if $baseInfo already carried one from resolving its own base) - semantic-type
            // alias matching keys off this name.
            [, $selfLocal] = explode('#', $key, 2);
            $baseInfo = new TypeInfo(kind: $baseInfo->kind, phpType: $baseInfo->phpType, dateOnly: $baseInfo->dateOnly, facets: $baseInfo->facets, namedType: $selfLocal);
        }

        $this->resolvedSimple[$key] = $baseInfo;

        return $baseInfo;
    }

    /** Logs $reason via note() and returns the plain-string fallback type-info - every silent generator fallback goes through here so none can be added without a diagnostic. */
    private function fallbackScalar(string $reason): TypeInfo
    {
        $this->note($reason);

        return self::stringFallback();
    }

    /** Merges $restriction's own facets onto $typeInfo's (already possibly inherited) ones - own facets win on key collision. No-op if $typeInfo isn't a scalar. */
    private function mergeFacets(TypeInfo $typeInfo, \DOMElement $restriction): TypeInfo
    {
        if (TypeKind::Scalar !== $typeInfo->kind) {
            return $typeInfo;
        }
        $facets = [...$typeInfo->facets, ...$this->extractFacets($restriction)];
        if ([] === $facets) {
            return $typeInfo;
        }

        return new TypeInfo(
            kind: $typeInfo->kind,
            phpType: $typeInfo->phpType,
            dateOnly: $typeInfo->dateOnly,
            facets: $facets,
            namedType: $typeInfo->namedType,
        );
    }

    /**
     * Reads this restriction's own xs:length/minLength/maxLength/pattern/minInclusive/
     * maxInclusive/minExclusive/maxExclusive/totalDigits/fractionDigits facets - shallow, does
     * not walk further up a chain of nested named-simpleType restrictions.
     *
     * @return array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int}
     */
    private function extractFacets(\DOMElement $restriction): array
    {
        /** @var array<string, bool> which facet keys are integer-valued */
        static $intFacets = ['length' => true, 'minLength' => true, 'maxLength' => true, 'totalDigits' => true, 'fractionDigits' => true];
        /** @var array<string, bool> which xs:* child element names are recognized facets */
        static $knownFacets = [
            'length' => true, 'minLength' => true, 'maxLength' => true, 'pattern' => true,
            'minInclusive' => true, 'maxInclusive' => true, 'minExclusive' => true, 'maxExclusive' => true,
            'totalDigits' => true, 'fractionDigits' => true,
        ];

        $facets = [];
        foreach ($restriction->childNodes as $child) {
            if (!$child instanceof \DOMElement || self::XS_NS !== $child->namespaceURI || !isset($knownFacets[$child->localName])) {
                continue;
            }
            if (isset($facets[$child->localName])) {
                continue; // first occurrence wins, matches the previous item(0) lookup
            }
            $value = $child->getAttribute('value');
            $facets[$child->localName] = isset($intFacets[$child->localName]) ? (int) $value : $value;
        }

        /** @var array{length?: int, minLength?: int, maxLength?: int, pattern?: string, minInclusive?: string, maxInclusive?: string, minExclusive?: string, maxExclusive?: string, totalDigits?: int, fractionDigits?: int} $facets */
        return $facets;
    }

    /** Resolves either "xs:string" style primitives or a reference to another named simpleType. */
    private function resolvePrimitiveOrNamedSimpleType(\DOMElement $contextNode, string $qname): TypeInfo
    {
        [$ns, $local] = $this->resolveQName($contextNode, $qname);
        $key = $ns.'#'.$local;
        if (self::XS_NS !== $ns && isset($this->simpleTypes[$key])) {
            return $this->resolveSimpleTypeRef($key);
        }

        return new TypeInfo(kind: TypeKind::Scalar, phpType: Naming::xsPrimitiveToPhp($local), dateOnly: 'date' === $local);
    }

    /**
     * Wraps ensureEnumClass()'s result as a resolveXxxType()-style TypeInfo.
     *
     * @param \DOMNodeList<\DOMElement> $enumerations
     */
    private function toEnumResult(string $name, \DOMNodeList $enumerations, string $backingPhpType, string $namespace): TypeInfo
    {
        return new TypeInfo(kind: TypeKind::Enum, phpType: $this->ensureEnumClass($name, $enumerations, $backingPhpType, $namespace));
    }

    /** @param \DOMNodeList<\DOMElement> $enumerations */
    private function ensureEnumClass(string $simpleTypeName, \DOMNodeList $enumerations, string $backingPhpType, string $namespace): string
    {
        $backing = 'int' === $backingPhpType ? 'int' : 'string';
        $className = Naming::toClassName($simpleTypeName);

        $usedCaseNames = [];
        $cases = [];
        foreach ($enumerations as $enum) {
            $value = $enum->getAttribute('value');
            $caseName = Naming::toClassName($value);
            $baseCaseName = $caseName;
            $i = 2;
            while (isset($usedCaseNames[$caseName])) {
                $caseName = $baseCaseName.'_'.$i++;
            }
            $usedCaseNames[$caseName] = true;
            $literal = 'int' === $backing ? (string) (int) $value : var_export($value, true);
            $cases[] = "    case {$caseName} = {$literal};";
        }

        $body = implode("\n", $cases);
        $code = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            /**
             * XSD simpleType: {$simpleTypeName}
             */
            enum {$className}: {$backing}
            {
            {$body}
            }

            PHP;

        $this->writeFile($this->pathFor($namespace, $className), $code);

        return $namespace.'\\'.$className;
    }

    /** Resolves the type of an xs:element or xs:attribute node: named @var ref, or an inline anonymous xs:complexType/xs:simpleType child. */
    private function resolveParticleType(\DOMElement $node, string $ownerClassName, string $ownerNamespace): TypeInfo
    {
        $typeAttr = $node->getAttribute('type');
        if ('' !== $typeAttr) {
            [$ns, $local] = $this->resolveQName($node, $typeAttr);
            $key = $ns.'#'.$local;
            if (self::XS_NS !== $ns && isset($this->complexTypes[$key])) {
                return new TypeInfo(kind: TypeKind::Class_, phpType: $this->ensureComplexClass($key));
            }

            return $this->resolvePrimitiveOrNamedSimpleType($node, $typeAttr);
        }

        $xp = $this->xpath($this->ownerDocOf($node));
        $nestedNamespace = $ownerNamespace.'\\'.$ownerClassName;

        // xs:complexType/xs:simpleType are mutually exclusive per the XSD schema-for-schema - an
        // element/attribute has at most one, so a combined query is unambiguous.
        $inlineType = $this->query($xp, 'xs:complexType | xs:simpleType', $node)->item(0);
        if ($inlineType instanceof \DOMElement && 'complexType' === $inlineType->localName) {
            $anonName = Naming::toClassName($node->getAttribute('name'));
            $className = $this->buildComplexClass($inlineType, $anonName, $nestedNamespace);

            return new TypeInfo(kind: TypeKind::Class_, phpType: $className);
        }

        if ($inlineType instanceof \DOMElement) {
            $restriction = $this->query($xp, 'xs:restriction', $inlineType)->item(0);
            if (!$restriction instanceof \DOMElement) {
                return $this->fallbackScalar("inline simpleType without xs:restriction on '{$node->getAttribute('name')}', mapped to plain string");
            }

            /** @var \DOMNodeList<\DOMElement> $enumerations */
            $enumerations = $this->query($xp, 'xs:enumeration', $restriction);
            if ($enumerations->length > 0) {
                $anonName = Naming::toClassName($node->getAttribute('name')).'Enum';
                $base = $this->resolvePrimitiveOrNamedSimpleType($restriction, $restriction->getAttribute('base'));

                // nested under the owner's namespace, like inline complex types above, so two
                // unrelated owners with a same-named inline enum member don't collide on output
                return $this->toEnumResult($anonName, $enumerations, $base->phpType, $nestedNamespace);
            }

            $base = $this->resolvePrimitiveOrNamedSimpleType($restriction, $restriction->getAttribute('base'));

            return $this->mergeFacets($base, $restriction);
        }

        return $this->fallbackScalar("no @type/inline type definition on '{$node->getAttribute('name')}' (xs:anyType equivalent), mapped to plain string");
    }

    /** @return array{properties: list<Property>, choiceGroups: list<array{fields: list<string>, required: bool}>} */
    private function collectProperties(\DOMElement $ctNode, string $ownerClassName, string $ownerNamespace): array
    {
        $xp = $this->xpath($this->ownerDocOf($ctNode));

        $properties = [];

        // xs:complexContent/xs:simpleContent are mutually exclusive per the XSD schema-for-schema -
        // a complexType has at most one, so a combined query is unambiguous.
        $content = $this->query($xp, 'xs:complexContent | xs:simpleContent', $ctNode)->item(0);

        $contentContainer = $ctNode;
        $baseProperties = [];

        if ($content instanceof \DOMElement && 'complexContent' === $content->localName) {
            $ext = $this->query($xp, 'xs:extension', $content)->item(0);
            if (!$ext instanceof \DOMElement) {
                // xs:restriction narrows/redefines the base content model rather than adding to
                // it; treated like extension (union of base + local content) rather than
                // implemented properly. Warn loud instead of silently generating a wrong shape.
                $ext = $this->query($xp, 'xs:restriction', $content)->item(0);
                $this->warn("'{$ownerClassName}' uses complexContent/xs:restriction, treated as extension");
            }
            if ($ext instanceof \DOMElement) {
                [$baseNs, $baseLocal] = $this->resolveQName($ext, $ext->getAttribute('base'));
                $baseKey = $baseNs.'#'.$baseLocal;
                if ('' !== $baseLocal && 'anyType' !== $baseLocal && isset($this->complexTypes[$baseKey])) {
                    $baseProperties = $this->resolveBaseProperties($baseKey);
                }
                $contentContainer = $ext;
            } else {
                $this->warn("'{$ownerClassName}' has complexContent without xs:extension or xs:restriction, skipping base resolution");
            }
        } elseif ($content instanceof \DOMElement) {
            $ext = $this->query($xp, 'xs:extension', $content)->item(0);
            if ($ext instanceof \DOMElement) {
                $baseInfo = $this->resolvePrimitiveOrNamedSimpleType($ext, $ext->getAttribute('base'));
                $properties[] = $this->makeProperty('value', PropertyRole::Text, false, false, $baseInfo, null);
                $contentContainer = $ext;
            } else {
                $this->warn("'{$ownerClassName}' has simpleContent without xs:extension, skipping value property");
            }
        }

        $properties = [...$baseProperties, ...$properties];

        /** @var array<int, array{particle: \DOMElement, members: array{phpName: string, prop: Property}[], directChildCount: int}> keyed by spl_object_id() of the enclosing xs:choice particle */
        $choiceGroups = [];

        foreach ($this->query($xp, 'xs:sequence | xs:choice | xs:all', $contentContainer) as $particle) {
            if (!$particle instanceof \DOMElement) {
                $this->warn("'{$ownerClassName}' has a non-element sequence/choice/all node, skipping");
                continue;
            }
            foreach ($this->collectParticleElements($particle) as [$el, $choiceParticle]) {
                $refAttr = $el->getAttribute('ref');
                if ('' !== $refAttr && !$el->hasAttribute('name')) {
                    [$refNs, $refLocal] = $this->resolveQName($el, $refAttr);
                    $refKey = $refNs.'#'.$refLocal;
                    if (!isset($this->elements[$refKey])) {
                        $this->warn("unknown element ref '{$refLocal}'");
                        continue;
                    }
                    $typeSource = $this->elements[$refKey];
                    $name = $typeSource->getAttribute('name');
                    $doc = $this->extractDoc($el) ?? $this->extractDoc($typeSource);
                } else {
                    $typeSource = $el;
                    $name = $el->getAttribute('name');
                    $doc = $this->extractDoc($el);
                }
                // default="..."/fixed="..." only legal on the actual declaration - $typeSource,
                // never a bare xs:element ref="..." site.
                $doc = $this->appendXsdDefaultHint($doc, $typeSource);

                $minOccurs = $el->hasAttribute('minOccurs') ? $el->getAttribute('minOccurs') : '1';
                $maxOccurs = $el->hasAttribute('maxOccurs') ? $el->getAttribute('maxOccurs') : '1';
                $isArray = 'unbounded' === $maxOccurs || (is_numeric($maxOccurs) && (int) $maxOccurs > 1);
                // xs:choice elements are mutually exclusive alternatives, not independently
                // required siblings - nullable regardless of the element's own minOccurs.
                $nullable = ('0' === $minOccurs || $choiceParticle instanceof \DOMElement) && !$isArray;

                $typeInfo = $this->resolveParticleType($typeSource, $ownerClassName, $ownerNamespace);

                $prop = $this->makeProperty($name, PropertyRole::Element, $isArray, $nullable, $typeInfo, $doc);
                $properties[] = $prop;

                if ($choiceParticle instanceof \DOMElement) {
                    $groupKey = spl_object_id($choiceParticle);
                    $choiceGroups[$groupKey] ??= [
                        'particle' => $choiceParticle,
                        'members' => [],
                        'directChildCount' => $this->query($xp, 'xs:element | xs:sequence | xs:choice | xs:all | xs:group', $choiceParticle)->length,
                    ];
                    // $prop's own identity (not a separately-tracked DOM node) is what the
                    // dedup-survivor check below compares against - "did this exact Property
                    // instance survive dedup under its phpName" tells apart "a same-named
                    // non-choice property (e.g. an xs:attribute) won the de-dup instead",
                    // without Property itself needing to carry DOM bookkeeping.
                    $choiceGroups[$groupKey]['members'][] = ['phpName' => $prop->phpName, 'prop' => $prop];
                }
            }
        }

        foreach ($this->collectAttributes($contentContainer) as $attr) {
            $name = $attr->getAttribute('name');
            if ('' === $name) {
                $refAttr = $attr->getAttribute('ref');
                $this->warn('' !== $refAttr
                    ? "xs:attribute ref='{$refAttr}' is not supported, skipping"
                    : 'xs:attribute without name or ref, skipping');
                continue;
            }
            $use = $attr->hasAttribute('use') ? $attr->getAttribute('use') : 'optional';
            $typeInfo = $this->resolveParticleType($attr, $ownerClassName, $ownerNamespace);
            $doc = $this->appendXsdDefaultHint($this->extractDoc($attr), $attr);

            $properties[] = $this->makeProperty($name, PropertyRole::Attribute, false, 'required' !== $use, $typeInfo, $doc);
        }

        // de-dup by phpName, last one wins (own properties override inherited base ones with same name)
        $byName = [];
        foreach ($properties as $p) {
            $byName[$p->phpName] = $p;
        }

        // "exactly one of" only makes sense for a fixed, 1:1 set of alternatives.
        $exactlyOneOfGroups = [];
        foreach ($choiceGroups as $group) {
            $particle = $group['particle'];

            // repeatable choice (maxOccurs > 1 on the xs:choice itself): picks a branch per
            // occurrence, not once overall - not representable by this generator's flat scalar
            // properties either way, so skip the constraint rather than emit a wrong one.
            if ($particle->hasAttribute('maxOccurs') && '1' !== $particle->getAttribute('maxOccurs')) {
                continue;
            }

            // only count members that survived de-dup as *themselves* (same source element) -
            // a same-named later property (base override, or an xs:attribute processed after
            // elements) can win the de-dup and silently replace a choice element under its
            // phpName otherwise.
            $names = [];
            foreach ($group['members'] as $member) {
                if (($byName[$member['phpName']] ?? null) === $member['prop']) {
                    $names[] = $member['phpName'];
                }
            }

            // A mismatch against the choice particle's own direct-child count means at least one
            // branch didn't map 1:1 to a single surviving property: a multi-element branch
            // (nested xs:sequence/xs:group/xs:choice directly under this xs:choice - one atomic
            // alternative, not N independent members, which this generator can't express since it
            // only tracks flat membership, not per-branch grouping), an unresolved ref, or the
            // de-dup collision above. Warn and skip the constraint instead of emitting a wrong
            // one - elements stay nullable regardless.
            if (\count($names) !== $group['directChildCount']) {
                if ([] !== $names) {
                    $this->warn("xs:choice on '{$ownerClassName}' is not fully representable as an \"exactly one of\" constraint (multi-element branch, unresolved ref, or a name collision), skipping the constraint for this choice");
                }
                continue;
            }

            // choice itself optional (minOccurs="0" on the xs:choice): zero branches selected is
            // valid too - "at most one", not "exactly one".
            $required = !$particle->hasAttribute('minOccurs') || '0' !== $particle->getAttribute('minOccurs');

            $exactlyOneOfGroups[] = ['fields' => $names, 'required' => $required];
        }

        return ['properties' => array_values($byName), 'choiceGroups' => $exactlyOneOfGroups];
    }

    /**
     * Resolves (and caches) a base complexType's own property list for a complexContent/extension
     * chain. Owner for inline anonymous nested types is the base type's own identity - the same
     * one ensureComplexClass() uses when the base is generated standalone - not the identity of
     * whichever subclass happens to extend it. That makes the result independent of the caller,
     * so a given $baseKey always resolves to the same properties (safe to cache) and inline nested
     * types declared on the base get generated once, under the base's own namespace, instead of
     * once per subclass.
     *
     * @return list<Property>
     */
    private function resolveBaseProperties(string $baseKey): array
    {
        if (isset($this->basePropertiesCache[$baseKey])) {
            return $this->basePropertiesCache[$baseKey];
        }

        [$baseXsdNs, $baseLocalName] = explode('#', $baseKey, 2);
        if (isset($this->basePropertiesInProgress[$baseKey])) {
            $this->warn("circular complexContent/extension involving '{$baseLocalName}', stopping inheritance chain");

            return [];
        }

        $this->basePropertiesInProgress[$baseKey] = true;
        $className = Naming::toClassName($baseLocalName);
        $namespace = $this->namespaceFor($baseXsdNs)->phpNamespace;
        // Base type's own choiceGroups deliberately not propagated to the subclass here - an
        // "exactly one of" defined on the base would need to be re-emitted on every subclass that
        // extends it, not currently needed by any complexContent/extension chain in the schema.
        $result = $this->collectProperties($this->complexTypes[$baseKey], $className, $namespace)['properties'];
        unset($this->basePropertiesInProgress[$baseKey]);

        return $this->basePropertiesCache[$baseKey] = $result;
    }

    /** Builds one property-model entry; shared by the simpleContent-value, element, and attribute sites in collectProperties(). */
    private function makeProperty(string $name, PropertyRole $role, bool $isArray, bool $nullable, TypeInfo $typeInfo, ?string $doc): Property
    {
        return new Property(
            phpName: PropertyRole::Text === $role ? $name : Naming::toPropName($name),
            xmlName: PropertyRole::Text === $role ? null : $name,
            role: $role,
            isArray: $isArray,
            nullable: $nullable,
            kind: match ($typeInfo->kind) {
                TypeKind::Scalar => 'scalar',
                TypeKind::Class_ => 'class',
                TypeKind::Enum => 'enum',
            },
            phpType: $typeInfo->phpType,
            dateOnly: $typeInfo->dateOnly,
            facets: $typeInfo->facets,
            namedType: $typeInfo->namedType,
            doc: $doc,
        );
    }

    private function extractDoc(\DOMElement $node): ?string
    {
        $xp = $this->xpath($this->ownerDocOf($node));
        $doc = $this->query($xp, 'xs:annotation/xs:documentation', $node)->item(0);
        if (!$doc instanceof \DOMElement) {
            return null;
        }
        $normalized = preg_replace('/\s+/', ' ', $doc->textContent);
        if (null === $normalized) {
            return null;
        }
        $text = trim($normalized);

        return '' === $text ? null : $text;
    }

    /**
     * Appends the XSD default="..."/fixed="..." value as a doc hint - informational only, doesn't
     * change nullability/serialization:
     * a value omitted by the caller keeps generating as PHP `null`, the server applies the XSD
     * default on its own side. Only $declNode's own attributes count - not applicable to an
     * xs:element ref="..." site, which XSD forbids from redeclaring default/fixed itself.
     */
    private function appendXsdDefaultHint(?string $doc, \DOMElement $declNode): ?string
    {
        $hints = [];
        if ($declNode->hasAttribute('default')) {
            $hints[] = "XSD-Default: {$declNode->getAttribute('default')}";
        }
        if ($declNode->hasAttribute('fixed')) {
            $hints[] = "XSD-Fixed: {$declNode->getAttribute('fixed')}";
        }
        if ([] === $hints) {
            return $doc;
        }
        $hint = '('.implode(', ', $hints).')';

        return null === $doc ? $hint : "{$doc} {$hint}";
    }

    private function fqType(Property $p, TypeRenderContext $ctx): string
    {
        if (!\in_array($p->kind, ['class', 'enum'], true)) {
            return $p->phpType;
        }

        return $ctx->render($p->phpType);
    }

    private function phpPropertyType(Property $p, TypeRenderContext $ctx): string
    {
        if ($p->isArray) {
            return 'array';
        }

        return ($p->nullable ? '?' : '').$this->fqType($p, $ctx);
    }

    private function phpDocType(Property $p, TypeRenderContext $ctx): string
    {
        $type = $this->fqType($p, $ctx);

        return $p->isArray ? $type.'[]' : $type;
    }

    private function hasDefault(Property $p): bool
    {
        return $p->isArray || $p->nullable;
    }

    private function buildComplexClass(\DOMElement $ctNode, string $className, string $namespace): string
    {
        ['properties' => $properties, 'choiceGroups' => $choiceGroups] = $this->collectProperties($ctNode, $className, $namespace);

        // required (no default) params must precede optional ones in a PHP constructor
        usort($properties, fn (Property $a, Property $b): int => $this->hasDefault($a) <=> $this->hasDefault($b));

        // Resolve attributesFor() once per property up front so the `use` block (built from
        // every fqcn seen across the whole class) and the constructor body (rendered below,
        // short name unless its basename collides with another fqcn in this class) agree.
        $propertyAttrs = array_map($this->config->attributeStrategy->attributesFor(...), $properties);

        // fqcn => shortName, collected from both attribute usages and cross-namespace property
        // types. A property type living in $namespace itself needs neither an import nor a
        // leading backslash - PHP already resolves an unqualified name against the file's own
        // namespace - so those are tracked separately and never enter the collision check.
        $imports = [];
        $sameNamespaceTypes = [];
        foreach ($propertyAttrs as $attrs) {
            foreach ($attrs as $attr) {
                $imports[$attr['fqcn']] ??= Naming::basename($attr['fqcn']);
            }
        }
        foreach ($properties as $p) {
            if (!\in_array($p->kind, ['class', 'enum'], true)) {
                continue;
            }
            $fqcn = $p->phpType;
            $lastBackslash = strrpos($fqcn, '\\');
            if (false !== $lastBackslash && substr($fqcn, 0, $lastBackslash) === $namespace) {
                $sameNamespaceTypes[$fqcn] = true;
                continue;
            }
            $imports[$fqcn] ??= Naming::basename($fqcn);
        }
        if ([] !== $choiceGroups) {
            $imports[Validator\ExactlyOneOf::class] ??= 'ExactlyOneOf';
        }
        $shortNameUsedBy = array_count_values($imports);
        $ctx = new TypeRenderContext($sameNamespaceTypes, $imports, $shortNameUsedBy);

        $useLines = [];
        foreach ($imports as $fqcn => $shortName) {
            if (1 === $shortNameUsedBy[$shortName]) {
                $useLines[] = "use {$fqcn};";
            }
        }
        sort($useLines);

        $ctorLines = [];
        foreach ($properties as $i => $p) {
            $type = $this->phpPropertyType($p, $ctx);
            $default = $p->isArray ? ' = []' : ($p->nullable ? ' = null' : '');

            $doc = null !== $p->doc ? str_replace('*/', '* /', $p->doc) : null;
            if ($p->isArray) {
                // symfony/property-info's PhpDocExtractor needs an explicit @var tag (PHP has no
                // generics) to resolve the array item type for denormalization.
                $ctorLines[] = '        /** @var '.$this->phpDocType($p, $ctx).(null !== $doc ? " {$doc}" : '').' */';
            } elseif (null !== $doc) {
                $ctorLines[] = "        /** {$doc} */";
            }
            foreach ($propertyAttrs[$i] as $attr) {
                $rendered = $ctx->render($attr['fqcn']);
                $ctorLines[] = "        #[{$rendered}({$attr['args']})]";
            }
            $ctorLines[] = "        public {$type} \${$p->phpName}{$default},";
            $ctorLines[] = '';
        }
        $ctorBody = rtrim(implode("\n", $ctorLines));

        $classAttrLines = [];
        foreach ($choiceGroups as $group) {
            $fieldsLiteral = "['".implode("', '", $group['fields'])."']";
            $args = "fields: {$fieldsLiteral}".($group['required'] ? '' : ', required: false');
            $classAttrLines[] = "#[{$ctx->render(Validator\ExactlyOneOf::class)}({$args})]\n";
        }

        $importsBlock = [] === $useLines ? '' : implode("\n", $useLines)."\n\n";
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$importsBlock}";
        $code .= implode('', $classAttrLines);
        $code .= "final readonly class {$className}\n{\n    public function __construct(\n{$ctorBody}\n    ) {\n    }\n}\n";

        $this->writeFile($this->pathFor($namespace, $className), $code);

        return $namespace.'\\'.$className;
    }

    private function ensureComplexClass(string $key): string
    {
        if (isset($this->generatedComplex[$key])) {
            return $this->generatedComplex[$key];
        }

        if (!isset($this->complexTypes[$key])) {
            throw new \RuntimeException("complexType not found: {$key}");
        }

        [$xsdNs, $local] = explode('#', $key, 2);
        $namespace = $this->namespaceFor($xsdNs)->phpNamespace;
        $className = Naming::toClassName($local);

        return $this->generatedComplex[$key] = $this->buildComplexClass($this->complexTypes[$key], $className, $namespace);
    }

    /** Finds the registered namespace mapping $namespace descends from (possibly a nested/anonymous type) and derives its file path from that mapping's output directory. */
    private function pathFor(string $namespace, string $className): string
    {
        $best = null;
        foreach ($this->config->namespaceMap as $mapping) {
            if ($namespace === $mapping->phpNamespace || str_starts_with($namespace, $mapping->phpNamespace.'\\')) {
                // longest phpNamespace prefix wins - one nested inside another (e.g. "App\Foo"
                // and "App\Foo\Bar") must resolve to the more specific mapping, not whichever
                // happens to come first in Config::$namespaceMap.
                if (null === $best || \strlen($mapping->phpNamespace) > \strlen($best->phpNamespace)) {
                    $best = $mapping;
                }
            }
        }
        if (null === $best) {
            throw new \RuntimeException("No output directory mapped for PHP namespace '{$namespace}'");
        }
        $relativeNs = substr($namespace, \strlen($best->phpNamespace));

        return $best->outputDir.str_replace('\\', '/', $relativeNs).'/'.$className.'.php';
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = \dirname($path);
        if (!isset($this->createdDirs[$dir])) {
            @mkdir($dir, 0o777, true);
            $this->createdDirs[$dir] = true;
        }
        file_put_contents($path, $content);
        ++$this->written;
    }
}
