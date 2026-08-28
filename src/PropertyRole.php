<?php

declare(strict_types=1);

namespace XsdObjectMapper;

enum PropertyRole
{
    case Element;
    case Attribute;
    case Text;
}
