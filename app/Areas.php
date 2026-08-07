<?php
/**
 * app/Areas.php
 * Bengaluru locality catalogue for the registration form.
 * Kept in one place so the form, validation, and admin stay in sync.
 */

declare(strict_types=1);

final class Areas
{
    /**
     * Zone label => list of localities (A–Z within each zone).
     */
    private const BY_ZONE = [
        'Central Bengaluru' => [
            'Ashok Nagar',
            'Basavanagudi',
            'Chamarajapet',
            'Chickpet',
            'City Market / KR Market',
            'Cubbonpet',
            'Gandhi Bazaar',
            'Gandhinagar',
            'Jayanagar',
            'KG Road / Majestic',
            'Langford Town',
            'Malleshwaram',
            'Rajajinagar',
            'Richmond Town',
            'Seshadripuram',
            'Shivajinagar',
            'South End Circle',
            'Ulsoor / Halasuru',
            'VV Puram',
            'Wilson Garden',
        ],
        'North Bengaluru' => [
            'BEL Layout',
            'Dasarahalli',
            'Devanahalli',
            'Dodballapur Road',
            'Ganganagar',
            'Hebbal',
            'Hennur',
            'Jakkur',
            'Jalahalli',
            'Kempegowda International Airport Area',
            'Kodigehalli',
            'Kogilu',
            'Mathikere',
            'Nagawara',
            'Peenya',
            'RT Nagar',
            'Sahakarnagar',
            'Singanayakanahalli',
            'Thanisandra',
            'Vidyaranyapura',
            'Yelahanka',
            'Yeshwanthpur',
        ],
        'South Bengaluru' => [
            'Anjanapura',
            'Arekere',
            'Banashankari',
            'Bannerghatta Road',
            'Begur',
            'Bommanahalli',
            'BTM Layout',
            'Chandapura',
            'Electronic City',
            'Girinagar',
            'Gottigere',
            'Hongasandra',
            'HSR Layout',
            'Hulimavu',
            'ISRO Layout',
            'Jigani',
            'JP Nagar',
            'Kathriguppe',
            'Kengeri',
            'Konanakunte',
            'Koramangala',
            'Kudlu / Singasandra',
            'Kumaraswamy Layout',
            'Mysore Road',
            'Padmanabhanagar',
            'Rajarajeshwari Nagar',
            'Uttarahalli',
            'Yelachenahalli',
        ],
        'East Bengaluru' => [
            'Avalahalli',
            'Baiyyappanahalli',
            'Banaswadi',
            'Bellandur',
            'Benson Town',
            'Brookefield',
            'Cooke Town',
            'Cox Town',
            'CV Raman Nagar',
            'Domlur',
            'Frazer Town',
            'Gunjur',
            'Hoodi',
            'Hope Farm Junction',
            'Horamavu',
            'Immadihalli',
            'Indiranagar',
            'ITPL / Whitefield',
            'Kadubeesanahalli',
            'Kadugodi',
            'Kalyan Nagar',
            'Kammanahalli',
            'KR Puram',
            'Mahadevapura',
            'Marathahalli',
            'Old Airport Road',
            'Ramamurthy Nagar',
            'Sarjapur Road',
            'TC Palya',
            'Varthur',
            'Whitefield',
        ],
        'West Bengaluru' => [
            'Attiguppe',
            'Basaveshwaranagar',
            'Binnypet / Cottonpet',
            'Chandra Layout',
            'Gayathri Nagar',
            'Herohalli',
            'Kamakshipalya',
            'Laggere',
            'Magadi Road',
            'Mahalakshmi Layout',
            'Nagarbhavi',
            'Nandini Layout',
            'Okalipuram',
            'Sunkadakatte',
            'Tollgate',
            'Vijayanagar',
            'West of Chord Road',
        ],
        'Other' => [
            'Other Bengaluru Area',
            'Outside Bengaluru',
        ],
    ];

    /** @return array<string, list<string>> */
    public static function byZone(): array
    {
        return self::BY_ZONE;
    }

    /** @return list<string> */
    public static function all(): array
    {
        $all = [];
        foreach (self::BY_ZONE as $areas) {
            foreach ($areas as $area) {
                $all[] = $area;
            }
        }
        return $all;
    }

    public static function isValid(string $area): bool
    {
        return in_array($area, self::all(), true);
    }
}
