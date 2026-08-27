<?php

declare(strict_types=1);

namespace Xsd2Php;

enum TypeKind
{
    case Scalar;
    case Class_;
    case Enum;
}
