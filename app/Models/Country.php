<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A country, with the calling code and national-number length its phones use. */
class Country extends Model
{
    public $timestamps = false;

    protected $fillable = ['country_name', 'iso_code', 'phone_code', 'min_length', 'max_length'];

    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }

    /** The calling code with its plus sign, e.g. "+91". */
    public function dialCode(): string
    {
        return '+'.$this->phone_code;
    }
}
