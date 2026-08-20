<?php

namespace App\Enums;

enum DocumentType: string
{
    case CC = 'CC'; // Cédula de Ciudadanía
    case CE = 'CE'; // Cédula de Extranjería
    case NIT = 'NIT'; // Empresa/Persona Jurídica
    case PASSPORT = 'PASSPORT'; // Pasaporte
}