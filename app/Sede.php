<?php

namespace App;

enum Sede: string
{
    case Madrid = 'ES-MD';
    case Barcelona = 'ES-CT';
    case Sevilla = 'ES-AN';
    case Valencia = 'ES-VC';
    case Bilbao = 'ES-PV';
    case ACoruna = 'ES-GA';
    case Zaragoza = 'ES-AR';
    case Palma = 'ES-IB';
    case Murcia = 'ES-MC';
    case Valladolid = 'ES-CL';
    case Toledo = 'ES-CM';
    case Merida = 'ES-EX';
    case Oviedo = 'ES-AS';
    case Santander = 'ES-CB';
    case Logrono = 'ES-RI';
    case Pamplona = 'ES-NC';
    case SantaCruzDeTenerife = 'ES-CN';
    case Ceuta = 'ES-CE';
    case Melilla = 'ES-ML';

    public static function porDefecto(): self
    {
        return self::Madrid;
    }

    public function municipio(): string
    {
        return match ($this) {
            self::Madrid => 'Madrid',
            self::Barcelona => 'Barcelona',
            self::Sevilla => 'Sevilla',
            self::Valencia => 'Valencia',
            self::Bilbao => 'Bilbao',
            self::ACoruna => 'A Coruña',
            self::Zaragoza => 'Zaragoza',
            self::Palma => 'Palma',
            self::Murcia => 'Murcia',
            self::Valladolid => 'Valladolid',
            self::Toledo => 'Toledo',
            self::Merida => 'Mérida',
            self::Oviedo => 'Oviedo',
            self::Santander => 'Santander',
            self::Logrono => 'Logroño',
            self::Pamplona => 'Pamplona',
            self::SantaCruzDeTenerife => 'Santa Cruz de Tenerife',
            self::Ceuta => 'Ceuta',
            self::Melilla => 'Melilla',
        };
    }

    public function autonomia(): string
    {
        return match ($this) {
            self::Madrid => 'Madrid',
            self::Barcelona => 'Cataluña',
            self::Sevilla => 'Andalucía',
            self::Valencia => 'Comunidad Valenciana',
            self::Bilbao => 'País Vasco',
            self::ACoruna => 'Galicia',
            self::Zaragoza => 'Aragón',
            self::Palma => 'Islas Baleares',
            self::Murcia => 'Murcia',
            self::Valladolid => 'Castilla y León',
            self::Toledo => 'Castilla-La Mancha',
            self::Merida => 'Extremadura',
            self::Oviedo => 'Asturias',
            self::Santander => 'Cantabria',
            self::Logrono => 'La Rioja',
            self::Pamplona => 'Navarra',
            self::SantaCruzDeTenerife => 'Canarias',
            self::Ceuta => 'Ceuta',
            self::Melilla => 'Melilla',
        };
    }

    public function comunidad(): string
    {
        return $this->autonomia();
    }

    public function etiqueta(): string
    {
        return $this->municipio().' · '.$this->autonomia();
    }
}
