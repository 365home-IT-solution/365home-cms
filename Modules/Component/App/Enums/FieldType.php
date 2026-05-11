<?php

namespace Modules\Component\App\Enums;

enum FieldType: string
{
    case TEXT = 'string';
    case NUMBER = 'number';
    case ARRAY = 'array';
    case TOGGLE = 'bool';
}
