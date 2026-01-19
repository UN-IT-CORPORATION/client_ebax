<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'nom_entreprise',
        'adresse_municipale',
        'ville',
        'code_postal',
        'telephone',
        'courriel',
    ];
}
