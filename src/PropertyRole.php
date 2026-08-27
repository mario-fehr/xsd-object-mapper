<?php

declare(strict_types=1);

namespace Xsd2Php;

enum PropertyRole
{
    case Element;
    case Attribute;
    case Text;
}
