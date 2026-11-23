<?php

namespace App\Support;

use App\Models\Country;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * A mobile number checked against its country's real numbering plan
 * (Google's libphonenumber - the same data the browser's libphonenumber-js uses)
 * and the length range stored on the country.
 */
final class MobileNumber
{
    /**
     * @return array{code: string, national: string, stored: string}|null null when it is not a valid number for the country
     */
    public static function parse(string $input, Country $country): ?array
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        // A few territories have no numbering plan on file: only the length range can be checked.
        if ($util->getMetadataForRegion($country->iso_code) === null) {
            $digits = preg_replace('/\D+/', '', $input);
            $length = strlen((string) $digits);

            return ($length >= $country->min_length && $length <= $country->max_length)
                ? ['code' => '+'.$country->phone_code, 'national' => $digits, 'stored' => '+'.$country->phone_code.' '.$digits]
                : null;
        }

        try {
            $number = $util->parse($input, $country->iso_code);
        } catch (NumberParseException) {
            return null;
        }

        // A number typed with another country's code is not valid for this country, and neither is
        // a landline, a toll-free or a premium number: this is the owner's mobile.
        if (! $util->isValidNumberForRegion($number, $country->iso_code)
            || ! in_array($util->getNumberType($number), [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
            return null;
        }

        $national = (string) $number->getNationalNumber();
        $length = strlen($national);

        if ($length < (int) $country->min_length || $length > (int) $country->max_length) {
            return null;
        }

        $code = '+'.$number->getCountryCode();

        return ['code' => $code, 'national' => $national, 'stored' => $code.' '.$national];
    }
}
