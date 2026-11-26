<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Grows the demo data set a little on every run, so the platform looks busy:
 * new customers, booking requests (with payments, reviews and notifications),
 * the occasional new venue, and owners/customers acting on pending bookings.
 *
 * Only ever ADDS rows or advances DEMO bookings (users @demo.eventgenius.pk and
 * their stores/venues). Real accounts, stores and bookings are never touched.
 * Disabled unless DEMO_DATA_GROWTH=true (config app.demo_growth).
 */
class DemoGrow extends Command
{
    protected $signature = 'demo:grow
        {--bookings=25 : New bookings to add}
        {--clients=3 : New demo customers to add}
        {--venue-chance=15 : Percent chance of adding a new demo venue}
        {--progress=12 : Pending/unpaid demo bookings to move forward}
        {--store-chance=10 : Percent chance of opening a new demo store (prefers cities without one)}
        {--stores=0 : Open this many new demo stores now (each with a first venue)}
        {--force : Run even when DEMO_DATA_GROWTH is off}';

    protected $description = 'Add a batch of realistic demo data (additive, demo accounts only)';

    /** Pakistani cities: [province, lat, lng, local areas]. */
    private const CITIES = [
        'Lahore' => ['Punjab', 31.5204, 74.3587, ['Gulberg', 'DHA', 'Johar Town', 'Model Town', 'Bahria Town', 'Garden Town', 'Canal Road', 'Faisal Town', 'Wapda Town', 'Cantt']],
        'Karachi' => ['Sindh', 24.8607, 67.0011, ['Clifton', 'DHA', 'Gulshan-e-Iqbal', 'PECHS', 'North Nazimabad', 'Saddar', 'Gulistan-e-Jauhar', 'Bahadurabad', 'Malir', 'Korangi']],
        'Islamabad' => ['Islamabad Capital Territory', 33.6844, 73.0479, ['F-7', 'F-10', 'E-11', 'G-9', 'Blue Area', 'DHA', 'Bahria Enclave', 'I-8', 'F-6', 'Pir Sohawa']],
        'Rawalpindi' => ['Punjab', 33.5651, 73.0169, ['Saddar', 'Bahria Town', 'Satellite Town', 'Westridge', 'Chaklala', 'Commercial Market', 'Peshawar Road', 'Gulraiz']],
        'Faisalabad' => ['Punjab', 31.4504, 73.1350, ['D Ground', 'Madina Town', 'Peoples Colony', 'Canal Road', 'Susan Road', 'Kohinoor City', 'Jinnah Colony', 'Satiana Road']],
        'Multan' => ['Punjab', 30.1575, 71.5249, ['Bosan Road', 'Gulgasht Colony', 'Cantt', 'Shah Rukn-e-Alam', 'Wapda Town', 'Nishtar Road', 'Shujabad Road']],
        'Peshawar' => ['Khyber Pakhtunkhwa', 34.0151, 71.5249, ['Hayatabad', 'University Road', 'Saddar', 'Tehkal', 'Ring Road', 'Warsak Road', 'Gulbahar', 'DHA']],
        'Sialkot' => ['Punjab', 32.4945, 74.5229, ['Cantt', 'Paris Road', 'Kashmir Road', 'Defence Road', 'Model Town', 'Ugoki']],
        'Gujranwala' => ['Punjab', 32.1877, 74.1945, ['Satellite Town', 'Model Town', 'DC Colony', 'Citi Housing', 'GT Road', 'Peoples Colony']],
        'Hyderabad' => ['Sindh', 25.3960, 68.3578, ['Latifabad', 'Qasimabad', 'Auto Bhan Road', 'Saddar', 'Citizen Colony', 'Hirabad']],
        'Quetta' => ['Balochistan', 30.1798, 66.9750, ['Jinnah Road', 'Satellite Town', 'Samungli Road', 'Airport Road', 'Cantt', 'Zarghoon Road']],
        'Bahawalpur' => ['Punjab', 29.3956, 71.6836, ['Model Town', 'Satellite Town', 'Cantt', 'Baghdad Road', 'Shahdara']],
        'Sargodha' => ['Punjab', 32.0836, 72.6711, ['Satellite Town', 'University Road', 'Faisalabad Road', 'Cantt']],
        'Sukkur' => ['Sindh', 27.7052, 68.8574, ['Military Road', 'Barrage Road', 'Workshop Road', 'Minara Road']],
        'Larkana' => ['Sindh', 27.5570, 68.2264, ['Station Road', 'VIP Road', 'Sachal Colony']],
        'Abbottabad' => ['Khyber Pakhtunkhwa', 34.1688, 73.2215, ['Supply', 'Mandian', 'Jinnahabad', 'Kakul Road', 'Cantt']],
        'Mardan' => ['Khyber Pakhtunkhwa', 34.1986, 72.0404, ['Sheikh Maltoon Town', 'Bank Road', 'Mall Road']],
        'Mingora' => ['Khyber Pakhtunkhwa', 34.7717, 72.3602, ['Saidu Sharif', 'Fizagat', 'Makan Bagh', 'Kanju']],
        'Gujrat' => ['Punjab', 32.5731, 74.0789, ['GT Road', 'Bhimber Road', 'Rehman Shaheed Road', 'Shadman Colony']],
        'Sahiwal' => ['Punjab', 30.6682, 73.1114, ['Farid Town', 'Civil Lines', 'Jahaz Ground']],
        'Rahim Yar Khan' => ['Punjab', 28.4202, 70.2952, ['Shahi Road', 'Model Town', 'Abbasia Town']],
        'Jhelum' => ['Punjab', 32.9425, 73.7257, ['GT Road', 'Civil Lines', 'Kala Gujran']],
        'Sheikhupura' => ['Punjab', 31.7167, 73.9850, ['Lahore Road', 'Housing Colony', 'Sargodha Road']],
        'Okara' => ['Punjab', 30.8138, 73.4534, ['Depalpur Road', 'Satellite Town', 'Renala Road']],
        'Dera Ghazi Khan' => ['Punjab', 30.0459, 70.6403, ['Jampur Road', 'Model Town', 'Block 13']],
        'Kasur' => ['Punjab', 31.1187, 74.4508, ['Railway Road', 'Steel Bagh', 'Lahore Road']],
        'Chiniot' => ['Punjab', 31.7200, 72.9783, ['Sargodha Road', 'Faisalabad Road', 'Muhalla Garh']],
        'Murree' => ['Punjab', 33.9070, 73.3943, ['Mall Road', 'Kashmir Point', 'Bhurban']],
        'Nawabshah' => ['Sindh', 26.2442, 68.4100, ['Housing Society', 'Mohni Bazar', 'Sakrand Road']],
        'Mirpur Khas' => ['Sindh', 25.5276, 69.0111, ['Satellite Town', 'Umerkot Road', 'Station Road']],
        'Muzaffarabad' => ['Azad Kashmir', 34.3700, 73.4711, ['Chattar', 'Upper Adda', 'Domel']],
        'Mirpur' => ['Azad Kashmir', 33.1484, 73.7517, ['Allama Iqbal Road', 'Sector F-1', 'Chakswari Road']],
        'Gilgit' => ['Gilgit-Baltistan', 35.9208, 74.3080, ['Jutial', 'Zulfiqarabad', 'Airport Road']],
        'Skardu' => ['Gilgit-Baltistan', 35.2971, 75.6333, ['Satpara Road', 'College Road', 'Hussainabad']],
        'Gwadar' => ['Balochistan', 25.1264, 62.3225, ['Marine Drive', 'New Town', 'Fish Harbour Road']],
    ];

    private const SLOTS = [['10:00', '14:00'], ['15:00', '18:30'], ['19:00', '23:30']];

    private const IMAGES = [
        'wedding'   => ['01', '02', '06', '15', '19', '21', '23', '24', '25', '28', '29', '30'],
        'party'     => ['03', '12', '13', '14', '17', '24'],
        'corporate' => ['09', '10', '11'],
        'concert'   => ['04', '08', '16', '18', '22', '26', '27'],
    ];

    private const EVENT_NAMES = [
        'weddings' => ['%s Wedding Reception', 'Baraat of %s', '%s Shaadi', 'Walima of %s', 'Nikkah Ceremony – %s'],
        'engagement_parties' => ['%s Engagement', 'Mangni of %s'],
        'bridal_showers' => ['%s Mehndi Night', 'Dholki for %s'],
        'anniversaries' => ['%s 25th Anniversary', 'Anniversary Dinner – %s'],
        'religious_events' => ['Milad Gathering – %s Family', 'Quran Khwani – %s Family'],
        'birthday_parties' => ['%s\'s Birthday Bash', 'Surprise Party for %s'],
        'baby_showers' => ['%s Baby Shower', 'Aqiqa of %s Family'],
        'graduation_parties' => ['%s Graduation Party'],
        'corporate_events' => ['Annual Dinner – %s & Co', 'Product Launch by %s Tech'],
        'conferences' => ['Pakistan Tech Summit', 'Annual Sales Conference', 'HealthCare Pakistan Expo'],
        'seminars_workshops' => ['Digital Marketing Workshop', 'Startup Founders Bootcamp', 'Leadership Training Day'],
        'concerts_music_events' => ['Sufi Night Live', 'Qawwali Evening', 'Indie Music Fest'],
        'festivals' => ['Winter Food Festival', 'Basant Spring Festival', 'Kids Summer Mela'],
    ];

    private const FIRST = ['Adeel', 'Aiman', 'Asad', 'Bushra', 'Faisal', 'Ghazala', 'Haris', 'Huma', 'Imran', 'Javeria',
        'Kashif', 'Laiba', 'Moiz', 'Noor', 'Rameez', 'Saima', 'Shahzaib', 'Tooba', 'Umair', 'Yusra', 'Zeeshan', 'Anum',
        'Arslan', 'Mehwish', 'Raheel', 'Sidra', 'Taimoor', 'Amna', 'Ammar', 'Fiza', 'Hamid', 'Rabia', 'Shoaib', 'Nida',
        'Waleed', 'Sobia', 'Farhan', 'Zara', 'Kamran', 'Hina', 'Salman', 'Alina', 'Danyal', 'Mariam', 'Junaid', 'Eman',
        'Abdullah', 'Areesha', 'Babar', 'Dua', 'Ehsan', 'Fariha', 'Ghulam', 'Hafsa', 'Irfan', 'Jannat', 'Kiran', 'Luqman',
        'Maheen', 'Nabeel', 'Omer', 'Parveen', 'Qasim', 'Rimsha', 'Sameer', 'Tayyaba', 'Uzair', 'Wajiha', 'Yasir', 'Zoya',
        'Aqsa', 'Basit', 'Hoorain', 'Mustafa', 'Nashit', 'Shiza', 'Talal', 'Urooj', 'Wahab', 'Zainab'];

    private const MIDDLE = ['Ali', 'Ahmed', 'Hassan', 'Fatima', 'Zahra', 'Usman', 'Bilal', 'Ayesha', 'Hamza', 'Iqbal',
        'Raza', 'Noor', 'Sultan', 'Jahan', 'Ul Haq', 'Ur Rehman', 'Bano', 'Shah', 'Akhtar', 'Mehmood'];

    private const LAST = ['Abbasi', 'Awan', 'Bhatti', 'Cheema', 'Dar', 'Gill', 'Jamil', 'Kazmi', 'Lodhi', 'Memon', 'Nadeem',
        'Niazi', 'Paracha', 'Rana', 'Saleem', 'Shah', 'Toor', 'Warraich', 'Zaidi', 'Yousaf', 'Qureshi', 'Siddiqui', 'Malik',
        'Chaudhry', 'Baig', 'Hashmi', 'Mirza', 'Butt', 'Khattak', 'Afridi', 'Soomro', 'Baloch', 'Ansari', 'Durrani',
        'Gondal', 'Jatoi', 'Khokhar', 'Leghari', 'Mughal', 'Naqvi', 'Rizvi', 'Sethi', 'Tiwana', 'Wattoo', 'Yousafzai',
        'Bukhari', 'Gilani', 'Hayat', 'Junejo', 'Kiyani', 'Mangi', 'Pirzada', 'Rathore', 'Shaikh', 'Talpur', 'Usmani'];

    private Carbon $now;
    private array $activeSlugs = [];
    private array $notifs = [];
    private array $touchedVenues = [];
    private array $counts = ['stores' => 0, 'clients' => 0, 'venues' => 0, 'bookings' => 0, 'payments' => 0, 'reviews' => 0, 'progressed' => 0];

    public function handle(): int
    {
        if (!config('app.demo_growth') && !$this->option('force')) {
            $this->line('Demo growth is off (set DEMO_DATA_GROWTH=true). Nothing done.');
            return self::SUCCESS;
        }

        $this->now = Carbon::now();
        $this->activeSlugs = DB::table('categories')->where('is_active', true)->whereNull('deleted_at')->pluck('slug')->all();

        DB::transaction(function () {
            $stores = $this->demoStores();
            if (!$stores) {
                $this->warn('No demo stores found — run DemoDataSeeder first.');
                return;
            }

            $this->addClients(max(0, (int) $this->option('clients')));

            // New demo stores, spreading into cities that have none yet.
            $newStores = max(0, (int) $this->option('stores'));
            if (mt_rand(1, 100) <= (int) $this->option('store-chance')) {
                $newStores++;
            }
            for ($i = 0; $i < $newStores; $i++) {
                if ($store = $this->addStore()) {
                    $stores[] = $store;
                    $this->addVenue($store);
                    if (mt_rand(1, 100) <= 50) {
                        $this->addVenue($store);
                    }
                }
            }

            if (mt_rand(1, 100) <= (int) $this->option('venue-chance')) {
                $this->addVenue($this->storeNeedingVenues($stores));
            }

            $venues = $this->demoVenues();
            $clients = DB::table('users')->where('role', 'client')->where('email', 'like', '%' . DemoDataSeeder::DEMO_DOMAIN)
                ->whereNull('deleted_at')->get(['id', 'name', 'created_at'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'created_at' => Carbon::parse($c->created_at)])->all();

            for ($i = 0, $n = max(0, (int) $this->option('bookings')); $i < $n && $venues && $clients; $i++) {
                $this->addBooking($venues[array_rand($venues)], $clients);
            }

            $this->progressBookings(max(0, (int) $this->option('progress')));
            $this->flushNotifications();
            $this->refreshVenueStats(array_keys($this->touchedVenues));
        });

        $c = $this->counts;
        $this->info(sprintf('[%s] demo:grow +%d stores, +%d clients, +%d venues, +%d bookings, +%d payments, +%d reviews, %d progressed',
            $this->now->toDateTimeString(), $c['stores'], $c['clients'], $c['venues'], $c['bookings'], $c['payments'], $c['reviews'], $c['progressed']));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ lookups

    private function demoStores(): array
    {
        return DB::table('stores')->join('users', 'users.id', '=', 'stores.user_id')
            ->where('users.email', 'like', '%' . DemoDataSeeder::DEMO_DOMAIN)
            ->where('stores.status', 'approved')->whereNull('stores.deleted_at')
            ->get(['stores.id', 'stores.name', 'stores.city', 'stores.state', 'stores.user_id',
                'stores.latitude', 'stores.longitude', 'stores.created_at'])
            ->map(fn ($s) => (array) $s)->all();
    }

    private function demoVenues(): array
    {
        $rows = DB::table('venues')->join('stores', 'stores.id', '=', 'venues.store_id')
            ->join('users', 'users.id', '=', 'stores.user_id')
            ->where('users.email', 'like', '%' . DemoDataSeeder::DEMO_DOMAIN)
            ->where('venues.status', 'active')->whereNull('venues.deleted_at')->where('stores.status', 'approved')
            ->get(['venues.id', 'venues.name', 'venues.store_id', 'venues.capacity_min', 'venues.capacity_max',
                'venues.price_per_head', 'venues.event_types', 'venues.operating_start', 'venues.created_at', 'venues.is_featured',
                'stores.name as store_name', 'stores.user_id as owner_id']);

        $paid = DB::table('venue_amenities')->whereIn('venue_id', $rows->pluck('id'))->where('is_paid', true)
            ->whereNull('deleted_at')->get(['venue_id', 'price'])->groupBy('venue_id');

        $venues = [];
        foreach ($rows as $v) {
            $types = array_values(array_intersect(json_decode($v->event_types ?? '[]', true) ?: [], $this->activeSlugs));
            if (!$types || !$v->price_per_head) {
                continue;
            }
            $entry = [
                'id' => $v->id, 'name' => $v->name, 'store_id' => $v->store_id, 'store_name' => $v->store_name,
                'owner_id' => $v->owner_id, 'cap_min' => (int) $v->capacity_min, 'cap_max' => (int) $v->capacity_max,
                'price_per_head' => (float) $v->price_per_head, 'types' => $types, 'open' => $v->operating_start ?: '10:00',
                'created_at' => Carbon::parse($v->created_at),
                'paid' => ($paid[$v->id] ?? collect())->pluck('price')->map(fn ($p) => (float) $p)->all(),
            ];
            $venues[] = $entry;
            if ($v->is_featured) {
                $venues[] = $entry; // featured venues get a bit more demand
            }
        }

        return $venues;
    }

    // ------------------------------------------------------------------ clients

    private function addClients(int $n): void
    {
        $password = Hash::make(DemoDataSeeder::PASSWORD);
        $prefixes = ['0300', '0301', '0303', '0312', '0321', '0322', '0333', '0334', '0345'];

        for ($i = 0; $i < $n; $i++) {
            // Unique full name: first [+ middle] + last, checked against every existing user.
            $name = null;
            for ($try = 0; $try < 25 && !$name; $try++) {
                $candidate = self::FIRST[array_rand(self::FIRST)]
                    . ($try > 3 || mt_rand(1, 100) <= 25 ? ' ' . self::MIDDLE[array_rand(self::MIDDLE)] : '')
                    . ' ' . self::LAST[array_rand(self::LAST)];
                if (!DB::table('users')->where('name', $candidate)->exists()) {
                    $name = $candidate;
                }
            }
            if (!$name) {
                continue;
            }
            $email = Str::slug($name, '.') . DemoDataSeeder::DEMO_DOMAIN;
            $phone = null;
            for ($try = 0; $try < 10 && !$phone; $try++) {
                $candidate = $prefixes[array_rand($prefixes)] . '-' . mt_rand(1000000, 9999999);
                if (!DB::table('users')->where('phone', $candidate)->exists()) {
                    $phone = $candidate;
                }
            }
            if (!$phone || DB::table('users')->where('email', $email)->exists()) {
                continue;
            }
            $at = $this->now->copy()->subSeconds(mt_rand(0, 590));
            DB::table('users')->insert([
                'name' => $name, 'email' => $email, 'role' => 'client',
                'phone' => $phone,
                'is_active' => true, 'last_seen_at' => $at, 'email_verified_at' => $at, 'password' => $password,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $this->counts['clients']++;
        }
    }

    // ------------------------------------------------------------------ venues

    private function addVenue(array $store): void
    {
        $kinds = [
            'wedding'   => [['weddings', 'engagement_parties', 'bridal_showers', 'anniversaries'], ['Grand Marquee', 'Banquet Hall', 'Wedding Lawn', 'Royal Palace', 'Pearl Ballroom', 'Garden Pavilion'], [150, 1200], [2200, 6000]],
            'party'     => [['birthday_parties', 'baby_showers', 'graduation_parties', 'anniversaries', 'engagement_parties'], ['Party Hall', 'Rooftop Lounge', 'Celebration Courtyard', 'Family Terrace'], [20, 250], [1800, 4200]],
            'corporate' => [['corporate_events', 'conferences', 'seminars_workshops'], ['Conference Centre', 'Business Hub', 'Convention Hall', 'Executive Boardroom'], [30, 600], [1500, 3500]],
            'concert'   => [['concerts_music_events', 'festivals', 'corporate_events'], ['Arena', 'Open-Air Grounds', 'Music Dome'], [200, 3000], [1200, 2600]],
        ];
        $kind = array_rand($kinds);
        [$types, $suffixes, [$capLo, $capHi], [$pLo, $pHi]] = $kinds[$kind];
        $types = array_values(array_intersect($types, $this->activeSlugs));
        if (!$types) {
            return;
        }

        // Real neighbourhoods of the store's city (generic ones only for cities outside the catalogue).
        $areas = self::CITIES[$store['city']][3] ?? ['Cantt', 'Civil Lines', 'Model Town', 'Satellite Town', 'Main Boulevard', 'GT Road'];
        $area = $name = null;
        $flavours = ['', 'The ', 'Royal ', 'New ', 'Grand ', 'Al-Noor ', 'Shan-e-', 'Crystal ', 'Emerald ', 'Golden '];
        for ($try = 0; $try < 30 && !$name; $try++) {
            $a = $areas[array_rand($areas)];
            $candidate = trim($flavours[array_rand($flavours)] . $a . ' ' . $suffixes[array_rand($suffixes)]);
            if (!DB::table('venues')->where('name', $candidate)->exists()) {
                [$area, $name] = [$a, $candidate];
            }
        }
        if (!$name) {
            return;
        }
        $capMin = (int) (round(mt_rand($capLo, (int) ($capLo + ($capHi - $capLo) / 3)) / 10) * 10);
        $capMax = (int) (round(mt_rand(max($capMin + 50, (int) ($capHi / 2)), $capHi) / 10) * 10);
        $price  = (int) (round(mt_rand($pLo, $pHi) / 100) * 100);
        $open   = ['10:00', '12:00', '15:00'][mt_rand(0, 2)];
        $images = collect(self::IMAGES[$kind])->shuffle()->take(min(4, count(self::IMAGES[$kind])))->values()->all();

        // Place the venue near its store, else near another venue of the store, else the city centre.
        $anchor = (!empty($store['latitude']) && !empty($store['longitude']))
            ? (object) ['latitude' => (float) $store['latitude'], 'longitude' => (float) $store['longitude']]
            : (DB::table('venues')->where('store_id', $store['id'])->whereNotNull('latitude')->first(['latitude', 'longitude'])
                ?? (isset(self::CITIES[$store['city']]) ? (object) ['latitude' => self::CITIES[$store['city']][1], 'longitude' => self::CITIES[$store['city']][2]] : null));
        // Most venues of a store opened a while ago (so they have history); some are brand new.
        $storeSince = isset($store['created_at']) ? Carbon::parse($store['created_at']) : $this->now->copy()->subDays(200);
        $createdAt = mt_rand(1, 100) <= 70 && $storeSince->lt($this->now->copy()->subDays(30))
            ? $storeSince->copy()->addDays(mt_rand(1, max(1, (int) $storeSince->diffInDays($this->now) - 20)))->setTime(mt_rand(9, 20), mt_rand(0, 59))
            : $this->now->copy();
        $desc = [
            'wedding'   => "A {$this->adj()} venue in {$area} for weddings, mehndi and walima functions, with in-house catering, stage decor and ample parking.",
            'party'     => "A {$this->adj()} space in {$area} for birthdays, showers and family celebrations, with themed decor packages and a dedicated host.",
            'corporate' => "A {$this->adj()} business venue in {$area} with AV equipment, high-speed Wi-Fi and catering for conferences and training days.",
            'concert'   => "A {$this->adj()} live-event venue in {$area} with professional sound, lighting and a large stage for concerts and festivals.",
        ][$kind];

        $id = DB::table('venues')->insertGetId([
            'store_id' => $store['id'], 'name' => $name,
            'slug' => Str::slug($name . ' ' . $store['city']) . '-' . Str::lower(Str::random(5)),
            'description' => $desc, 'thumbnail' => "demo/{$images[0]}.jpg",
            'address' => "{$area}, {$store['city']}", 'city' => $store['city'], 'state' => $store['state'], 'country' => 'Pakistan',
            'latitude' => $anchor ? round($anchor->latitude + mt_rand(-300, 300) / 10000, 8) : null,
            'longitude' => $anchor ? round($anchor->longitude + mt_rand(-300, 300) / 10000, 8) : null,
            'capacity_min' => $capMin, 'capacity_max' => $capMax, 'area_sqft' => $capMax * mt_rand(18, 30), 'floors' => mt_rand(1, 2),
            'price_per_head' => $price, 'pricing_type' => 'per_head', 'dynamic_pricing' => false,
            'event_types' => json_encode($types), 'operating_start' => $open, 'operating_end' => '23:59',
            'status' => 'active', 'is_featured' => mt_rand(1, 100) <= 15,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        foreach ($images as $n => $img) {
            DB::table('venue_images')->insert([
                'venue_id' => $id, 'path' => "demo/{$img}.jpg", 'alt_text' => $name, 'is_primary' => $n === 0, 'sort_order' => $n,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }

        $amenities = [
            ['Air Conditioning', 'snowflake', false, null], ['Generator Backup', 'zap', false, null], ['Valet Parking', 'car', false, null],
            ['Wi-Fi', 'wifi', false, null], ['Prayer Area', 'moon', false, null], ['Sound System', 'speaker', true, 25000],
            ['Photography', 'camera', true, 45000], ['Stage Decoration', 'flower', true, 60000], ['LED Wall', 'monitor', true, 80000],
            ['DJ Setup', 'music', true, 35000],
        ];
        foreach (collect($amenities)->shuffle()->take(mt_rand(5, 8)) as [$aName, $icon, $isPaid, $aPrice]) {
            DB::table('venue_amenities')->insert([
                'venue_id' => $id, 'name' => $aName, 'icon' => $icon, 'is_paid' => $isPaid, 'price' => $aPrice,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }

        $this->counts['venues']++;
    }

    /** Prefer stores with few venues so new cities fill up; otherwise any store. */
    private function storeNeedingVenues(array $stores): array
    {
        if (mt_rand(1, 100) <= 60) {
            $counts = DB::table('venues')->whereIn('store_id', array_column($stores, 'id'))->whereNull('deleted_at')
                ->groupBy('store_id')->pluck(DB::raw('count(*)'), 'store_id');
            usort($stores, fn ($a, $b) => ($counts[$a['id']] ?? 0) <=> ($counts[$b['id']] ?? 0));
            return $stores[mt_rand(0, min(2, count($stores) - 1))];
        }
        return $stores[array_rand($stores)];
    }

    /** Opens an approved demo store (with owner account and public page) in a city that needs one. */
    private function addStore(): ?array
    {
        $perCity = DB::table('stores')->join('users', 'users.id', '=', 'stores.user_id')
            ->where('users.email', 'like', '%' . DemoDataSeeder::DEMO_DOMAIN)->whereNull('stores.deleted_at')
            ->groupBy('stores.city')->pluck(DB::raw('count(*)'), 'stores.city')->all();
        $empty = array_values(array_filter(array_keys(self::CITIES), fn ($c) => empty($perCity[$c])));
        $open  = array_values(array_filter(array_keys(self::CITIES), fn ($c) => ($perCity[$c] ?? 0) < 3));
        $pool = $empty ?: $open;
        if (!$pool) {
            return null;
        }
        $city = $pool[array_rand($pool)];
        [$state, $lat, $lng, $areas] = self::CITIES[$city];

        $suffixes = ['Banquets', 'Event Centre', 'Marquees', 'Celebrations', 'Venues', 'Grand Events', 'Halls', 'Event Lawns'];
        $name = null;
        foreach (collect($suffixes)->shuffle() as $suffix) {
            foreach (["{$city} {$suffix}", "Royal {$city} {$suffix}", "New {$city} {$suffix}"] as $candidate) {
                if (!DB::table('stores')->where('name', $candidate)->exists()) {
                    $name = $candidate;
                    break 2;
                }
            }
        }
        if (!$name) {
            return null;
        }

        // Owner account with a unique name, email and phone.
        $owner = null;
        for ($try = 0; $try < 25 && !$owner; $try++) {
            $full = self::FIRST[array_rand(self::FIRST)] . ' ' . self::LAST[array_rand(self::LAST)];
            $email = Str::slug($full, '.') . '.owner' . DemoDataSeeder::DEMO_DOMAIN;
            if (!DB::table('users')->where('name', $full)->orWhere('email', $email)->exists()) {
                $owner = [$full, $email];
            }
        }
        if (!$owner) {
            return null;
        }
        $phone = null;
        for ($try = 0; $try < 10 && !$phone; $try++) {
            $candidate = ['0300', '0301', '0312', '0321', '0333', '0345'][mt_rand(0, 5)] . '-' . mt_rand(1000000, 9999999);
            if (!DB::table('users')->where('phone', $candidate)->exists()) {
                $phone = $candidate;
            }
        }

        // Most stores have been on the platform for a while (so they build up bookings and reviews).
        $since = mt_rand(1, 100) <= 75
            ? $this->now->copy()->subDays(mt_rand(90, 420))->setTime(mt_rand(9, 20), mt_rand(0, 59))
            : $this->now->copy();
        $ownerId = DB::table('users')->insertGetId([
            'name' => $owner[0], 'email' => $owner[1], 'role' => 'store_owner', 'phone' => $phone,
            'is_active' => true, 'last_seen_at' => $this->now->copy()->subMinutes(mt_rand(5, 3000)),
            'email_verified_at' => $since, 'password' => Hash::make(DemoDataSeeder::PASSWORD),
            'created_at' => $since, 'updated_at' => $since,
        ]);

        $slug = Str::slug($name);
        if (DB::table('stores')->where('slug', $slug)->exists()) {
            $slug .= '-' . Str::lower(Str::random(4));
        }
        $area = $areas[array_rand($areas)];
        $storeLat = round($lat + mt_rand(-250, 250) / 10000, 7);
        $storeLng = round($lng + mt_rand(-250, 250) / 10000, 7);
        $hours = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d) => [$d => ['open' => $d === 'friday' ? '14:00' : '11:00', 'close' => '23:59', 'closed' => false]])->all();
        $hero = 'demo/' . collect(self::IMAGES['wedding'])->random() . '.jpg';
        $desc = "{$name} hosts weddings, family functions and corporate events in {$city}, with in-house catering, decor and a dedicated event coordinator.";

        $storeId = DB::table('stores')->insertGetId([
            'user_id' => $ownerId, 'name' => $name, 'slug' => $slug, 'description' => $desc,
            'logo' => null, 'cover_image' => $hero,
            'address' => "{$area}, {$city}", 'city' => $city, 'state' => $state, 'country' => 'Pakistan',
            'latitude' => $storeLat, 'longitude' => $storeLng, 'phone' => $phone,
            'email' => 'info@' . str_replace('-', '', $slug) . '.pk', 'website' => null, 'status' => 'approved',
            'business_hours' => json_encode($hours),
            'social_links' => json_encode(['facebook' => "https://facebook.com/{$slug}", 'instagram' => 'https://instagram.com/' . str_replace('-', '', $slug), 'twitter' => '', 'website' => '']),
            'created_at' => $since, 'updated_at' => $since,
        ]);
        DB::table('store_landing_pages')->insert([
            'store_id' => $storeId, 'hero_image' => $hero, 'tagline' => "Celebrations in {$city}, done right",
            'about_text' => $desc . ' Our coordinators handle timings, vendors and guest flow so you can enjoy your day.',
            'services' => json_encode([
                ['title' => 'In-house Catering', 'description' => 'Pakistani, Chinese and continental menus prepared fresh by our own chefs.'],
                ['title' => 'Decor & Stage', 'description' => 'Floral stages, lighting and theme decor designed to your colours.'],
                ['title' => 'Event Coordination', 'description' => 'A dedicated coordinator for timings, vendors and guest flow.'],
            ]),
            'faq' => json_encode([
                ['question' => 'How far in advance should I book?', 'answer' => 'For the wedding season (November to February) we recommend booking 2–3 months ahead.'],
                ['question' => 'Is there a backup generator?', 'answer' => 'Yes, full generator backup so load-shedding never interrupts your event.'],
            ]),
            'social_links' => json_encode([]), 'testimonials' => json_encode([]),
            'gallery' => json_encode(collect(self::IMAGES['wedding'])->shuffle()->take(4)->map(fn ($i) => "demo/{$i}.jpg")->values()->all()),
            'created_at' => $since, 'updated_at' => $since,
        ]);
        $this->notify($ownerId, 'store_approved', 'Store Approved!',
            "Congratulations! Your store \"{$name}\" has been approved. You can now list venues and accept bookings.",
            ['store_id' => $storeId, 'store_name' => $name], $since->copy()->addHours(6));

        $this->counts['stores']++;
        return ['id' => $storeId, 'name' => $name, 'city' => $city, 'state' => $state, 'user_id' => $ownerId,
            'latitude' => $storeLat, 'longitude' => $storeLng, 'created_at' => $since];
    }

    private function adj(): string
    {
        return ['modern', 'elegant', 'spacious', 'beautiful', 'well-equipped', 'charming'][mt_rand(0, 5)];
    }

    // ------------------------------------------------------------------ bookings

    private function addBooking(array $venue, array $clients): void
    {
        // Mostly fresh requests arriving now; the rest back-fills history (completed events, reviews).
        $fresh = mt_rand(1, 100) <= 55;
        [$date, $start, $end] = $this->freeSlot($venue, $fresh) ?? $this->freeSlot($venue, !$fresh) ?? [null, null, null];
        if (!$date) {
            return; // venue fully booked in the window
        }
        $past = $date->lt($this->now->copy()->startOfDay());

        $createdAt = $past
            ? $date->copy()->subDays(mt_rand(7, 60))->setTime(mt_rand(9, 22), mt_rand(0, 59))
            : $this->now->copy()->subSeconds(mt_rand(0, 590));
        if ($createdAt->lt($venue['created_at'])) {
            $createdAt = $venue['created_at']->copy()->addHours(mt_rand(1, 48));
        }
        if ($createdAt->gte($date)) {
            return;
        }
        $eligible = array_values(array_filter($clients, fn ($c) => $c['created_at']->lt($createdAt)));
        if (!$eligible) {
            return;
        }
        $client = $eligible[array_rand($eligible)];

        $type = $venue['types'][array_rand($venue['types'])];
        $tpls = self::EVENT_NAMES[$type] ?? ['%s Event'];
        $tpl  = $tpls[array_rand($tpls)];
        $who  = explode(' ', $client['name']);
        $eventName = str_contains($tpl, '%s') ? sprintf($tpl, preg_match('/Family|Co|Tech/', $tpl) ? end($who) : $who[0]) : $tpl;

        $guests = (int) (round(mt_rand($venue['cap_min'], max($venue['cap_min'], $venue['cap_max'])) / 10) * 10) ?: $venue['cap_min'];
        $paidAmen = collect($venue['paid'])->shuffle()->take(mt_rand(0, 2));
        $base  = round($venue['price_per_head'] * $guests, 2);
        $amen  = round($paidAmen->sum(), 2);
        $tax   = round(($base + $amen) * 0.05, 2);
        $total = round($base + $amen + $tax, 2);

        $r = mt_rand(1, 100);
        $status = $past
            ? ($r <= 80 ? 'completed' : ($r <= 93 ? 'cancelled' : 'rejected'))
            : ($r <= 85 ? 'pending' : 'confirmed');

        $confirmedAt = in_array($status, ['confirmed', 'completed'])
            ? $this->clampNow($createdAt->copy()->addHours(mt_rand(1, 48))) : null;
        $paid     = $status === 'completed' || ($status === 'confirmed' && mt_rand(1, 100) <= 40);
        $refunded = $status === 'cancelled' && mt_rand(1, 100) <= 40;
        $paidAt   = ($paid || $refunded) ? $this->clampNow(($confirmedAt ?? $createdAt)->copy()->addHours(mt_rand(1, 36))) : null;
        $cancelledAt = $status === 'cancelled' ? $this->clampNow(($paidAt ?? $createdAt)->copy()->addDays(mt_rand(1, 6))) : null;
        $updatedAt = $status === 'completed'
            ? $date->copy()->addDay()->setTime(2, 0)
            : max(array_filter([$createdAt, $confirmedAt, $paidAt, $cancelledAt]));

        $bookingId = DB::table('bookings')->insertGetId([
            'booking_number' => 'BK-' . strtoupper(substr(md5(uniqid('', true) . mt_rand()), 0, 13)),
            'venue_id' => $venue['id'], 'client_id' => $client['id'], 'store_id' => $venue['store_id'],
            'event_name' => $eventName, 'event_type' => $type,
            'special_requirements' => [null, null, null, 'Need a separate seating area for ladies.', 'Please arrange a vegetarian menu for some guests.',
                'Wheelchair access needed for two elderly guests.', 'Requesting extra parking for 50 cars.'][mt_rand(0, 6)],
            'expected_guests' => $guests, 'event_date' => $date->toDateString(),
            'start_time' => $start . ':00', 'end_time' => $end . ':00',
            'duration_hours' => (int) round(Carbon::parse($start)->diffInMinutes(Carbon::parse($end)) / 60),
            'base_price' => $base, 'amenities_price' => $amen, 'discount_amount' => 0, 'tax_amount' => $tax, 'total_amount' => $total,
            'status' => $status,
            'payment_status' => $refunded ? 'refunded' : ($paid ? 'paid' : 'unpaid'),
            'paid_at' => $paidAt,
            'cancellation_reason' => $status === 'cancelled' ? ['Event postponed due to family emergency.', 'Changed the city of the event.', 'Found a venue closer to home.'][mt_rand(0, 2)] : null,
            'rejection_reason' => $status === 'rejected' ? 'Venue already reserved for a private event on this date.' : null,
            'confirmed_at' => $confirmedAt, 'cancelled_at' => $cancelledAt,
            'created_at' => $createdAt, 'updated_at' => $updatedAt,
        ]);
        $this->counts['bookings']++;
        $this->touchedVenues[$venue['id']] = true;

        $data = ['booking_id' => $bookingId, 'venue_name' => $venue['name']];
        $this->notify($venue['owner_id'], 'new_booking_received', 'New Booking Received',
            "{$client['name']} has booked \"{$venue['name']}\" for {$date->toDateString()}.", $data, $createdAt);
        $this->notify($client['id'], 'booking_created', 'Booking Submitted',
            "Your booking for \"{$venue['name']}\" on {$date->toDateString()} has been submitted and is pending confirmation.", $data, $createdAt);
        if ($confirmedAt) {
            $this->notify($client['id'], 'booking_confirmed', 'Booking Confirmed!',
                "Great news! Your booking for \"{$venue['name']}\" has been confirmed by {$venue['store_name']}.", $data, $confirmedAt);
        }
        if ($status === 'rejected') {
            $this->notify($client['id'], 'booking_rejected', 'Booking Rejected',
                "Sorry, your booking for \"{$venue['name']}\" was declined by {$venue['store_name']}.", $data, $createdAt->copy()->addHours(5));
        }

        if ($paidAt) {
            $this->insertPayment($bookingId, $client['id'], $total, $paidAt, $refunded ? $cancelledAt : null);
            if (!$refunded) {
                $this->notify($venue['owner_id'], 'payment_received', 'Payment Received',
                    'Payment of PKR ' . number_format($total) . " received for \"{$venue['name']}\".", $data, $paidAt);
            }
        }

        if ($status === 'completed' && mt_rand(1, 100) <= 75) {
            $this->insertReview($venue, $client, $bookingId, $date, $data, $type);
        }
    }

    /** A free [date, start, end] for the venue, or null. Fresh = future dates, else past dates. */
    private function freeSlot(array $venue, bool $fresh): ?array
    {
        for ($try = 0; $try < 8; $try++) {
            $offset = $fresh ? mt_rand(3, 365) : -mt_rand(1, 730);
            $date = $this->now->copy()->addDays($offset)->startOfDay();
            if (!$fresh && $date->lt($venue['created_at']->copy()->startOfDay())) {
                continue;
            }
            $slots = array_values(array_filter(self::SLOTS, fn ($s) => $s[0] >= $venue['open']));
            [$start, $end] = $slots ? $slots[array_rand($slots)] : self::SLOTS[2];

            $clash = DB::table('bookings')->where('venue_id', $venue['id'])->whereDate('event_date', $date->toDateString())
                ->whereNotIn('status', ['cancelled', 'rejected'])->whereNull('deleted_at')
                ->where('start_time', '<', $end . ':00')->where('end_time', '>', $start . ':00')->exists();
            if (!$clash) {
                return [$date, $start, $end];
            }
        }

        return null;
    }

    private function insertPayment(int $bookingId, int $clientId, float $total, Carbon $paidAt, ?Carbon $refundedAt): void
    {
        $rate = (float) config('payfast.pkr_to_zar_rate', 0.065);
        $method = ['payd_fast', 'payd_fast', 'payd_fast', 'payd_fast', 'payd_fast', 'payd_fast', 'bank_transfer', 'bank_transfer', 'cash'][mt_rand(0, 8)];
        $gateway = $method === 'payd_fast';

        // Sometimes a failed card attempt first.
        if ($gateway && mt_rand(1, 100) <= 18) {
            $at = $paidAt->copy()->subMinutes(mt_rand(20, 180));
            DB::table('payments')->insert([
                'booking_id' => $bookingId, 'client_id' => $clientId, 'transaction_id' => null,
                'payment_reference' => 'EG-' . $bookingId . '-DEMO-' . strtoupper(Str::random(8)),
                'amount' => $total, 'currency' => 'PKR', 'gateway_amount' => round($total * $rate, 2), 'gateway_currency' => 'ZAR',
                'exchange_rate' => $rate, 'payment_method' => 'payd_fast', 'status' => 'failed',
                'gateway_response' => json_encode(['payment_status' => 'FAILED', 'demo' => true]),
                'notes' => ['Card declined by issuing bank.', 'Insufficient funds.', '3-D Secure verification failed.'][mt_rand(0, 2)],
                'created_at' => $at, 'updated_at' => $at->copy()->addMinutes(3),
            ]);
            $this->counts['payments']++;
        }

        DB::table('payments')->insert([
            'booking_id' => $bookingId, 'client_id' => $clientId,
            'transaction_id' => $gateway ? 'PF' . mt_rand(10000000, 99999999) . $bookingId
                : ($method === 'bank_transfer' ? 'IBFT' . mt_rand(100000000, 999999999) . $bookingId : null),
            'payment_reference' => 'EG-' . $bookingId . '-DEMO-' . strtoupper(Str::random(8)),
            'amount' => $total, 'currency' => 'PKR',
            'gateway_amount' => $gateway ? round($total * $rate, 2) : null, 'gateway_currency' => $gateway ? 'ZAR' : null,
            'exchange_rate' => $gateway ? $rate : null, 'payment_method' => $method,
            'status' => $refundedAt ? 'refunded' : 'completed',
            'gateway_response' => $gateway ? json_encode(['payment_status' => 'COMPLETE', 'demo' => true]) : null,
            'paid_at' => $paidAt, 'refunded_at' => $refundedAt,
            'notes' => $refundedAt ? 'Refunded after cancellation.'
                : ($method === 'bank_transfer' ? 'Paid via bank transfer (Meezan Bank).' : ($method === 'cash' ? 'Cash received at venue office.' : null)),
            'created_at' => $paidAt->copy()->subMinutes(5), 'updated_at' => $refundedAt ?? $paidAt,
        ]);
        $this->counts['payments']++;
    }

    private function insertReview(array $venue, array $client, int $bookingId, Carbon $date, array $data, string $type): void
    {
        $texts = [
            5 => [['Absolutely perfect!', 'The hall looked stunning and the food was delicious. Staff were polite and everything ran on time.'],
                  ['Best decision we made', 'From booking to the last guest leaving, the team was amazing. Highly recommended.'],
                  ['Exceeded expectations', 'Beautiful decor, great sound and excellent service. Our guests loved it.'],
                  ['Unforgettable evening', 'Everyone is still talking about the food. The coordinator handled every request calmly.']],
            4 => [['Great venue', 'Lovely place and good food. Parking got a bit crowded but the valet handled it.'],
                  ['Very good experience', 'Nice ambience and helpful staff. Air conditioning could have been a little stronger.'],
                  ['Would book again', 'Value for money and the coordinator was very responsive on WhatsApp.']],
            3 => [['Decent but room to improve', 'The venue is nice but the service was slow during dinner. Decor was as promised.'],
                  ['Average', 'Okay for the price. Generator took a minute to start during load-shedding.']],
            2 => [['Not what we expected', 'The hall was smaller than the photos suggested and the sound system had issues.']],
        ];
        $rating = [5, 5, 5, 4, 4, 4, 4, 3, 3, 2][mt_rand(0, 9)];
        [$title, $comment] = $texts[$rating][array_rand($texts[$rating])];
        // Compose a fresh review from sentence pools; never repeat an existing comment.
        for ($try = 0; $try < 12; $try++) {
            [$t, $c] = $this->composeReview($rating, $venue['name'], $type);
            if (!DB::table('reviews')->where('comment', $c)->exists()) {
                [$title, $comment] = [$t, $c];
                break;
            }
        }
        $at = $this->clampNow($date->copy()->addDays(mt_rand(1, 10))->setTime(mt_rand(10, 22), mt_rand(0, 59)));
        $sub = fn () => max(1, min(5, $rating + mt_rand(-1, 1)));
        $reply = mt_rand(1, 100) <= 50;

        DB::table('reviews')->insert([
            'venue_id' => $venue['id'], 'booking_id' => $bookingId, 'client_id' => $client['id'],
            'rating' => $rating, 'title' => $title, 'comment' => $comment,
            'cleanliness_rating' => $sub(), 'value_rating' => $sub(), 'service_rating' => $sub(), 'location_rating' => $sub(),
            'is_approved' => mt_rand(1, 100) <= 94,
            'store_reply' => $reply ? ($rating <= 3 ? 'Thanks for the honest feedback — we have shared it with our team and are working on it.'
                : ['Thank you so much for choosing us! It was a pleasure hosting your event.', 'We are glad you enjoyed it. Looking forward to hosting you again!'][mt_rand(0, 1)]) : null,
            'store_replied_at' => $reply ? $this->clampNow($at->copy()->addDay()) : null,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $this->counts['reviews']++;
        $this->notify($venue['owner_id'], 'new_review', 'New Review Received',
            "{$client['name']} rated \"{$venue['name']}\" {$rating}/5.", $data, $at);
    }

    /** [title, comment] built from independent sentence pools (thousands of combinations per rating). */
    private function composeReview(int $rating, string $venueName, string $type): array
    {
        $pick = fn (array $a) => $a[array_rand($a)];
        $titles = [
            5 => ['Absolutely perfect!', 'Best decision we made', 'Exceeded expectations', 'Unforgettable evening', 'Five stars, no doubt', 'Truly special day', 'Could not have asked for more', 'Highly recommended'],
            4 => ['Great venue', 'Very good experience', 'Would book again', 'Lovely event', 'Almost perfect', 'Good value', 'Happy overall'],
            3 => ['Decent but room to improve', 'Average experience', 'Okay for the price', 'Mixed feelings', 'Fine, nothing special'],
            2 => ['Not what we expected', 'Disappointing', 'Needs improvement', 'Below expectations'],
        ];
        $openers = [
            5 => ['We hosted our %s at %s and it was flawless.', '%s at %s was everything we hoped for.', 'Booked %s for our %s and it was the right call.'],
            4 => ['We had our %s at %s and it went really well.', '%s at %s was a good experience overall.', 'Booked %s for our %s and mostly loved it.'],
            3 => ['Our %s at %s was okay.', '%s at %s had its ups and downs.', 'We booked %s for our %s; it was fine.'],
            2 => ['Our %s at %s did not go as planned.', '%s at %s was a letdown.', 'We booked %s for our %s and were disappointed.'],
        ];
        // The event and the details praised match what was actually booked.
        $events = match ($type) {
            'weddings' => ['wedding', 'walima', 'baraat', 'nikkah', 'wedding reception'],
            'engagement_parties' => ['engagement', 'mangni', 'engagement dinner'],
            'bridal_showers' => ['mehndi', 'dholki', 'bridal shower'],
            'anniversaries' => ['anniversary', 'anniversary dinner'],
            'birthday_parties' => ['birthday party', 'surprise party', "son's birthday", "daughter's birthday"],
            'baby_showers' => ['baby shower', 'aqiqa'],
            'graduation_parties' => ['graduation party', 'convocation dinner'],
            'religious_events' => ['milad', 'quran khwani', 'family gathering'],
            'corporate_events' => ['company dinner', 'product launch', 'annual dinner'],
            'conferences' => ['conference', 'annual summit', 'industry conference'],
            'seminars_workshops' => ['workshop', 'training day', 'seminar'],
            'concerts_music_events' => ['concert', 'music night', 'qawwali night'],
            'festivals' => ['festival', 'food festival', 'mela'],
            default => ['event'],
        };
        $kind = match (true) {
            in_array($type, ['weddings', 'engagement_parties', 'bridal_showers'], true) => 'wedding',
            in_array($type, ['corporate_events', 'conferences', 'seminars_workshops'], true) => 'business',
            in_array($type, ['concerts_music_events', 'festivals'], true) => 'show',
            default => 'party',
        };
        $good = array_merge(
            ['Staff were polite and very professional.', 'Parking was easy thanks to the valet team.', 'The coordinator answered every call on WhatsApp.',
             'Air conditioning kept everyone comfortable.', 'Everything started exactly on time.', 'Generator backup kicked in instantly during load-shedding.'],
            [
                'wedding'  => ['The food was delicious and served hot.', 'The bridal room was clean and spacious.', 'The stage and lighting looked stunning in photos.',
                               'Guests kept praising the biryani.', 'The decor looked even better than the photos.', 'Separate family seating worked perfectly.'],
                'business' => ['The projector and mics worked flawlessly.', 'Wi-Fi held up for all attendees.', 'Lunch and tea breaks were well organised.',
                               'Breakout rooms were ready on time.', 'The AV technician was on hand all day.', 'Registration desk setup was smooth.'],
                'show'     => ['The sound system was crisp all night.', 'The lighting rig was impressive.', 'Entry gates handled the crowd well.',
                               'Security was well organised.', 'The stage was big enough for the full band.'],
                'party'    => ['The food was delicious and served hot.', 'The decor matched our theme perfectly.', 'Kids loved the play area.',
                               'The cake table setup looked lovely.', 'The host kept the evening fun and on schedule.'],
            ][$kind]
        );
        $bad = ['Dinner service was a bit slow.', 'Parking got crowded after 9 PM.', 'The AC struggled when the hall was full.',
            'Sound was too loud near the stage.', 'Washrooms needed more attention.', 'Setup started later than promised.',
            'Some decor items were different from the package.', 'Staff seemed short-handed during dinner.'];
        $closers = [
            5 => ['We will definitely book again.', 'Thank you to the whole team!', 'Highly recommend to anyone planning an event.'],
            4 => ['Would book again with a few tweaks.', 'Good value for money.', 'Recommended overall.'],
            3 => ['Fine for the price, but there is room to improve.', 'Might book again if the service improves.'],
            2 => ['Hope the management takes this feedback seriously.', 'We would not book again for a big event.'],
        ];
        $event = $pick($events);
        $opener = $pick($openers[$rating]);
        $opener = str_starts_with($opener, 'Booked') || str_starts_with($opener, 'We booked')
            ? sprintf($opener, $venueName, $event)
            : sprintf($opener, str_starts_with($opener, '%s') ? ucfirst($event) : $event, $venueName);
        $neg = $rating <= 4 ? collect($bad)->shuffle()->take($rating <= 3 ? 2 : 1)->all() : [];
        if ($rating === 4 && mt_rand(1, 100) <= 40) {
            $neg = [];
        }
        // Never praise and criticise the same thing in one review.
        $topic = fn (string $line) => collect(['parking', 'staff', 'decor', 'air', ' ac ', 'sound', 'dinner', 'food', 'time', 'setup'])
            ->filter(fn ($k) => str_contains(' ' . strtolower($line) . ' ', $k))->all();
        $negTopics = collect($neg)->flatMap($topic)->all();
        $pos = collect($good)->reject(fn ($g) => array_intersect($topic($g), $negTopics))
            ->shuffle()->take($rating >= 4 ? 2 : 1)->all();
        $comment = implode(' ', array_merge([$opener], $pos, $neg, [$pick($closers[$rating])]));

        return [$pick($titles[$rating]), $comment];
    }

    // ------------------------------------------------------------------ lifecycle

    /** Owners confirm/reject some pending demo requests; customers pay some confirmed ones. */
    private function progressBookings(int $n): void
    {
        if ($n === 0) {
            return;
        }
        $demoClient = fn ($q) => $q->select('id')->from('users')->where('email', 'like', '%' . DemoDataSeeder::DEMO_DOMAIN);

        $pending = DB::table('bookings')->where('status', 'pending')->whereDate('event_date', '>', $this->now->toDateString())
            ->where('created_at', '<', $this->now->copy()->subMinutes(30))->whereIn('client_id', $demoClient)->whereNull('deleted_at')
            ->inRandomOrder()->limit($n)->get(['id', 'venue_id', 'client_id', 'store_id']);

        foreach ($pending as $b) {
            $venue = DB::table('venues')->join('stores', 'stores.id', '=', 'venues.store_id')->where('venues.id', $b->venue_id)
                ->first(['venues.name', 'stores.name as store_name']);
            $data = ['booking_id' => $b->id, 'venue_name' => $venue->name ?? ''];
            if (mt_rand(1, 100) <= 82) {
                DB::table('bookings')->where('id', $b->id)->where('status', 'pending')
                    ->update(['status' => 'confirmed', 'confirmed_at' => $this->now, 'updated_at' => $this->now]);
                $this->notify($b->client_id, 'booking_confirmed', 'Booking Confirmed!',
                    "Great news! Your booking for \"{$venue->name}\" has been confirmed by {$venue->store_name}.", $data, $this->now);
            } else {
                DB::table('bookings')->where('id', $b->id)->where('status', 'pending')->update([
                    'status' => 'rejected', 'rejection_reason' => 'Venue already reserved for a private event on this date.',
                    'updated_at' => $this->now,
                ]);
                $this->notify($b->client_id, 'booking_rejected', 'Booking Rejected',
                    "Sorry, your booking for \"{$venue->name}\" was declined by {$venue->store_name}.", $data, $this->now);
            }
            $this->touchedVenues[$b->venue_id] = true;
            $this->counts['progressed']++;
        }

        $unpaid = DB::table('bookings')->where('status', 'confirmed')->where('payment_status', 'unpaid')
            ->whereDate('event_date', '>', $this->now->toDateString())->whereIn('client_id', $demoClient)->whereNull('deleted_at')
            ->inRandomOrder()->limit(max(1, intdiv($n, 2)))->get(['id', 'venue_id', 'client_id', 'store_id', 'total_amount']);

        foreach ($unpaid as $b) {
            $paidAt = $this->now->copy()->subSeconds(mt_rand(0, 590));
            DB::table('bookings')->where('id', $b->id)->where('payment_status', 'unpaid')
                ->update(['payment_status' => 'paid', 'paid_at' => $paidAt, 'updated_at' => $paidAt]);
            $this->insertPayment($b->id, $b->client_id, (float) $b->total_amount, $paidAt, null);
            $owner = DB::table('stores')->where('id', $b->store_id)->value('user_id');
            $venueName = DB::table('venues')->where('id', $b->venue_id)->value('name');
            if ($owner) {
                $this->notify($owner, 'payment_received', 'Payment Received',
                    'Payment of PKR ' . number_format((float) $b->total_amount) . " received for \"{$venueName}\".",
                    ['booking_id' => $b->id, 'venue_name' => $venueName], $paidAt);
            }
            $this->counts['progressed']++;
        }
    }

    // ------------------------------------------------------------------ helpers

    private function clampNow(Carbon $at): Carbon
    {
        return $at->gt($this->now) ? $this->now->copy()->subMinutes(mt_rand(1, 9)) : $at;
    }

    private function notify(int $userId, string $type, string $title, string $body, array $data, Carbon $at): void
    {
        $this->notifs[] = [$userId, $type, $title, $body, $data, $at];
    }

    private function flushNotifications(): void
    {
        $rows = [];
        foreach ($this->notifs as [$userId, $type, $title, $body, $data, $at]) {
            $isRead = $at->lt($this->now->copy()->subDays(3)) || mt_rand(1, 100) <= 20;
            $rows[] = [
                'user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body, 'data' => json_encode($data),
                'is_read' => $isRead, 'read_at' => $isRead ? $this->clampNow($at->copy()->addHours(4)) : null,
                'created_at' => $at, 'updated_at' => $at,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }
    }

    private function refreshVenueStats(array $venueIds): void
    {
        foreach ($venueIds as $id) {
            $q = DB::table('reviews')->where('venue_id', $id)->where('is_approved', true)->whereNull('deleted_at');
            $count = (clone $q)->count();
            DB::table('venues')->where('id', $id)->update([
                'avg_rating' => $count ? round((float) (clone $q)->avg('rating'), 2) : 0,
                'total_reviews' => $count,
                'total_bookings' => DB::table('bookings')->where('venue_id', $id)
                    ->whereIn('status', ['confirmed', 'completed'])->whereNull('deleted_at')->count(),
            ]);
        }
    }
}
