<?php

declare(strict_types=1);

namespace XsdObjectMapper;

enum TypeKind
{
    case Scalar;
    case Class_;
    case Enum;
}
