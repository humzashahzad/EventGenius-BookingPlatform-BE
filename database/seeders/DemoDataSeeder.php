<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo / sample data for showing the platform in the frontend.
 *
 *   php artisan db:seed --class=DemoDataSeeder --force
 *
 * Re-runnable: every run first removes the previous demo data (everything owned by
 * users whose email ends in DEMO_DOMAIN) and then inserts it again. Real accounts and
 * their data are never touched.
 *
 * Inserts use the query builder on purpose: Store is the tenancy model, so creating
 * stores through Eloquent would fire tenant-database creation.
 *
 * Images are expected in storage/app/public/demo/NN.jpg (stock photos from Unsplash).
 * Store logos and client avatars are generated here with GD.
 */
class DemoDataSeeder extends Seeder
{
    public const DEMO_DOMAIN = '@demo.eventgenius.pk';
    public const PASSWORD    = 'Demo@1234';
    public const TOTAL_CLIENTS  = 120;
    public const TOTAL_BOOKINGS = 1600;

    private Carbon $now;

    public function run(): void
    {
        mt_srand(20261118);
        $this->now = Carbon::now();

        DB::transaction(function () {
            $this->purge();

            [$owners, $clients] = $this->seedUsers();
            $stores  = $this->seedStores($owners);
            $venues  = $this->seedVenues($stores);
            $this->seedBookings($venues, $clients);
            $this->refreshVenueStats(array_column($venues, 'id'));
        });

        $this->command?->info('Demo data seeded. Password for every demo account: ' . self::PASSWORD);
    }

    // ------------------------------------------------------------------ purge

    private function purge(): void
    {
        $userIds = DB::table('users')->where('email', 'like', '%' . self::DEMO_DOMAIN)->pluck('id');
        if ($userIds->isEmpty()) {
            return;
        }

        $storeIds   = DB::table('stores')->whereIn('user_id', $userIds)->pluck('id');
        $venueIds   = DB::table('venues')->whereIn('store_id', $storeIds)->pluck('id');
        $bookingIds = DB::table('bookings')
            ->whereIn('store_id', $storeIds)->orWhereIn('client_id', $userIds)->pluck('id');

        DB::table('reviews')->whereIn('booking_id', $bookingIds)->orWhereIn('venue_id', $venueIds)->delete();
        DB::table('payments')->whereIn('booking_id', $bookingIds)->delete();
        DB::table('bookings')->whereIn('id', $bookingIds)->delete();
        DB::table('venue_amenities')->whereIn('venue_id', $venueIds)->delete();
        DB::table('venue_images')->whereIn('venue_id', $venueIds)->delete();
        DB::table('venues')->whereIn('id', $venueIds)->delete();
        DB::table('store_landing_pages')->whereIn('store_id', $storeIds)->delete();
        DB::table('stores')->whereIn('id', $storeIds)->delete();
        DB::table('notifications')->whereIn('user_id', $userIds)->delete();
        DB::table('user_sessions')->whereIn('user_id', $userIds)->delete();
        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }

    // ------------------------------------------------------------------ users

    private function seedUsers(): array
    {
        $password = Hash::make(self::PASSWORD);

        $ownerData = [
            'shalimar' => ['Ali Raza',      '0300-4512378'],
            'karachi'  => ['Sana Ahmed',    '0321-2289014'],
            'margalla' => ['Usman Tariq',   '0333-5170462'],
            'lyallpur' => ['Bilal Hussain', '0345-7623019'],
            'multan'   => ['Ayesha Khan',   '0301-6345981'],
            'sialkot'  => ['Kamran Malik',  '0312-8654120'],
            'pindi'    => ['Nadia Hussain', '0331-4478210'],
            'peshawar' => ['Zubair Khan',   '0315-9087345'],
        ];

        $clientData = [
            ['Fatima Noor',     '0300-1122334'], ['Ahmed Hassan',   '0321-9988776'],
            ['Zainab Ali',      '0333-4455667'], ['Hamza Iqbal',    '0345-2233445'],
            ['Maryam Siddiqui', '0301-7788990'], ['Omar Farooq',    '0312-5566778'],
            ['Hira Javed',      '0322-3344556'], ['Saad Mahmood',   '0334-6677889'],
            ['Ayesha Rehman',   '0300-9911223'], ['Danish Akhtar',  '0321-4433221'],
            ['Iqra Sheikh',     '0345-8899001'], ['Fahad Qureshi',  '0303-1212343'],
            ['Areeba Khalid',   '0300-5544332'], ['Hassan Raza',    '0321-6655443'],
            ['Mahnoor Butt',    '0333-7766554'], ['Talha Aslam',    '0345-1100229'],
            ['Sana Mirza',      '0301-2200338'], ['Waqas Anwar',    '0312-3300447'],
            ['Rida Fatima',     '0322-4400556'], ['Bilal Chaudhry', '0334-5500665'],
            ['Nimra Asif',      '0300-6600774'], ['Usama Riaz',     '0321-7700883'],
            ['Kinza Hashmi',    '0345-8800992'], ['Junaid Baig',    '0303-9900101'],
        ];

        // Top up to TOTAL_CLIENTS with generated, unique names.
        $first = ['Adeel', 'Aiman', 'Asad', 'Bushra', 'Faisal', 'Ghazala', 'Haris', 'Huma', 'Imran', 'Javeria',
                  'Kashif', 'Laiba', 'Moiz', 'Noor', 'Rameez', 'Saima', 'Shahzaib', 'Tooba', 'Umair', 'Yusra',
                  'Zeeshan', 'Anum', 'Arslan', 'Mehwish', 'Raheel', 'Sidra', 'Taimoor', 'Amna', 'Ammar', 'Fiza'];
        $last  = ['Abbasi', 'Awan', 'Bhatti', 'Cheema', 'Dar', 'Gill', 'Jamil', 'Kazmi', 'Lodhi', 'Memon',
                  'Nadeem', 'Niazi', 'Paracha', 'Rana', 'Saleem', 'Shah', 'Toor', 'Warraich', 'Zaidi', 'Yousaf'];
        $taken = array_flip(array_map(fn ($c) => $c[0], $clientData));
        $prefixes = ['0300', '0301', '0303', '0312', '0321', '0322', '0333', '0334', '0345'];
        while (count($clientData) < self::TOTAL_CLIENTS) {
            $name = $first[mt_rand(0, count($first) - 1)] . ' ' . $last[mt_rand(0, count($last) - 1)];
            if (isset($taken[$name])) {
                continue;
            }
            $taken[$name] = true;
            $clientData[] = [$name, $prefixes[mt_rand(0, count($prefixes) - 1)] . '-' . mt_rand(1000000, 9999999)];
        }

        $owners = [];
        foreach ($ownerData as $key => [$name, $phone]) {
            $email = Str::slug(explode(' ', $name)[0]) . '.owner' . self::DEMO_DOMAIN;
            $owners[$key] = $this->insertUser($name, $email, 'store_owner', $phone, $password, 420, null, 400);
        }

        $clients = [];
        foreach ($clientData as $i => [$name, $phone]) {
            $email  = Str::slug($name, '.') . self::DEMO_DOMAIN;
            $avatar = $this->makeMonogram("demo/avatars/client-" . ($i + 1) . ".png", $name, 256, $i);
            $clients[] = $this->insertUser($name, $email, 'client', $phone, $password, 390, $avatar);
        }

        return [$owners, $clients];
    }

    private function insertUser(string $name, string $email, string $role, string $phone, string $password, int $maxAgeDays, ?string $avatar = null, int $minAgeDays = 30): array
    {
        $created = $this->now->copy()->subDays(mt_rand($minAgeDays, $maxAgeDays))->setTime(mt_rand(9, 21), mt_rand(0, 59));
        $id = DB::table('users')->insertGetId([
            'name' => $name, 'email' => $email, 'role' => $role, 'phone' => $phone,
            'avatar' => $avatar, 'is_active' => true,
            'last_seen_at' => $this->now->copy()->subMinutes(mt_rand(5, 60 * 24 * 5)),
            'email_verified_at' => $created, 'password' => $password,
            'created_at' => $created, 'updated_at' => $created,
        ]);

        return ['id' => $id, 'name' => $name, 'created_at' => $created];
    }

    // ------------------------------------------------------------------ stores

    private function seedStores(array $owners): array
    {
        $hours = fn (string $open, string $close, ?string $friday = null) => collect(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
        )->mapWithKeys(fn ($d) => [$d => [
            'open' => $d === 'friday' && $friday ? $friday : $open, 'close' => $close, 'closed' => false,
        ]])->all();

        $defs = [
            'shalimar' => [
                'name' => 'Shalimar Banquets', 'city' => 'Lahore', 'state' => 'Punjab',
                'address' => '12-A Main Boulevard, Gulberg III', 'lat' => 31.5204, 'lng' => 74.3587,
                'color' => [176, 58, 46],
                'description' => 'Lahore\'s favourite address for grand weddings since 2008. Three signature venues, in-house catering and decor teams, and parking for 600 cars.',
                'tagline' => 'Where Lahore celebrates forever',
                'hero' => 'demo/29.jpg', 'gallery' => ['demo/01.jpg', 'demo/29.jpg', 'demo/25.jpg', 'demo/21.jpg', 'demo/19.jpg', 'demo/06.jpg'],
                'hours' => $hours('10:00', '23:59', '14:00'),
            ],
            'karachi' => [
                'name' => 'Karachi Event Centre', 'city' => 'Karachi', 'state' => 'Sindh',
                'address' => 'Plot 7, Block 4, Clifton', 'lat' => 24.8138, 'lng' => 67.0300,
                'color' => [21, 101, 132],
                'description' => 'Modern, sea-facing spaces for conferences, product launches, concerts and private parties. Full AV production and event management on site.',
                'tagline' => 'Big ideas need big rooms',
                'hero' => 'demo/11.jpg', 'gallery' => ['demo/11.jpg', 'demo/09.jpg', 'demo/10.jpg', 'demo/04.jpg', 'demo/17.jpg', 'demo/16.jpg'],
                'hours' => $hours('09:00', '23:59'),
            ],
            'margalla' => [
                'name' => 'Margalla Venues', 'city' => 'Islamabad', 'state' => 'Islamabad Capital Territory',
                'address' => 'Park Road, near F-7 Markaz', 'lat' => 33.7215, 'lng' => 73.0433,
                'color' => [46, 125, 50],
                'description' => 'Garden and hillside venues at the foot of the Margalla Hills, plus a business-class conference centre in the heart of Islamabad.',
                'tagline' => 'Celebrations with a view',
                'hero' => 'demo/15.jpg', 'gallery' => ['demo/15.jpg', 'demo/06.jpg', 'demo/23.jpg', 'demo/28.jpg', 'demo/13.jpg', 'demo/24.jpg'],
                'hours' => $hours('09:00', '23:00'),
            ],
            'lyallpur' => [
                'name' => 'Lyallpur Marquees', 'city' => 'Faisalabad', 'state' => 'Punjab',
                'address' => 'Canal Road, opposite Jinnah Colony', 'lat' => 31.4187, 'lng' => 73.0791,
                'color' => [123, 31, 162],
                'description' => 'Affordable, elegant marquees for weddings, mehndis and family functions. Packages include catering, stage and lighting.',
                'tagline' => 'Elegant events, honest prices',
                'hero' => 'demo/25.jpg', 'gallery' => ['demo/25.jpg', 'demo/02.jpg', 'demo/24.jpg', 'demo/12.jpg', 'demo/20.jpg'],
                'hours' => $hours('11:00', '23:30', '15:00'),
            ],
            'multan' => [
                'name' => 'Multan Royal Events', 'city' => 'Multan', 'state' => 'Punjab',
                'address' => 'Bosan Road, near Chungi No. 6', 'lat' => 30.2384, 'lng' => 71.4706,
                'color' => [191, 120, 20],
                'description' => 'A royal banquet palace and Multan\'s only indoor concert arena, run by a team that has hosted over 3,000 events.',
                'tagline' => 'The royal way to celebrate',
                'hero' => 'demo/01.jpg', 'gallery' => ['demo/01.jpg', 'demo/30.jpg', 'demo/22.jpg', 'demo/18.jpg', 'demo/08.jpg'],
                'hours' => $hours('10:00', '23:59'),
            ],
            'pindi' => [
                'name' => 'Pindi Celebrations', 'city' => 'Rawalpindi', 'state' => 'Punjab',
                'address' => 'Murree Road, Saddar', 'lat' => 33.5973, 'lng' => 73.0479,
                'color' => [0, 121, 107],
                'description' => 'Family-run wedding halls and party venues in the heart of Rawalpindi, known for generous food and warm hospitality.',
                'tagline' => 'Every family has a story, celebrate yours',
                'hero' => 'demo/02.jpg', 'gallery' => ['demo/02.jpg', 'demo/25.jpg', 'demo/03.jpg', 'demo/20.jpg', 'demo/12.jpg'],
                'hours' => $hours('11:00', '23:59', '15:00'),
            ],
            'peshawar' => [
                'name' => 'Khyber Grand Venues', 'city' => 'Peshawar', 'state' => 'Khyber Pakhtunkhwa',
                'address' => 'University Road, near Tehkal', 'lat' => 34.0151, 'lng' => 71.5249,
                'color' => [194, 24, 91],
                'description' => 'Grand halls and a modern conference centre in Peshawar, with traditional Peshawari catering and separate family arrangements.',
                'tagline' => 'Peshawari hospitality, grand celebrations',
                'hero' => 'demo/30.jpg', 'gallery' => ['demo/30.jpg', 'demo/01.jpg', 'demo/13.jpg', 'demo/10.jpg', 'demo/23.jpg'],
                'hours' => $hours('10:00', '23:30', '14:30'),
            ],
            // Pending store so the admin "approve store" flow has something to show.
            'sialkot' => [
                'name' => 'Sialkot Grand Events', 'city' => 'Sialkot', 'state' => 'Punjab',
                'address' => 'Paris Road, Cantt', 'lat' => 32.4945, 'lng' => 74.5229,
                'color' => [69, 90, 100], 'status' => 'pending',
                'description' => 'New banquet hall opening soon in Sialkot Cantt.',
                'tagline' => null, 'hero' => null, 'gallery' => [],
                'hours' => $hours('10:00', '23:00'),
            ],
        ];

        $stores = [];
        $i = 0;
        foreach ($defs as $key => $d) {
            $owner   = $owners[$key];
            $slug    = Str::slug($d['name']);
            $created = $owner['created_at']->copy()->addDays(1);
            $logo    = $this->makeMonogram("demo/logos/{$slug}.png", $d['name'], 400, $i++, $d['color']);
            $social  = [
                'facebook'  => "https://facebook.com/{$slug}",
                'instagram' => "https://instagram.com/" . str_replace('-', '', $slug),
                'twitter'   => '',
                'website'   => '',
            ];

            $id = DB::table('stores')->insertGetId([
                'user_id' => $owner['id'], 'name' => $d['name'], 'slug' => $slug,
                'description' => $d['description'], 'logo' => $logo, 'cover_image' => $d['hero'],
                'address' => $d['address'], 'city' => $d['city'], 'state' => $d['state'], 'country' => 'Pakistan',
                'latitude' => $d['lat'], 'longitude' => $d['lng'],
                'phone' => DB::table('users')->where('id', $owner['id'])->value('phone'),
                'email' => 'info@' . str_replace('-', '', $slug) . '.pk',
                'website' => null,
                'status' => $d['status'] ?? 'approved',
                'business_hours' => json_encode($d['hours']),
                'social_links' => json_encode($social),
                'created_at' => $created, 'updated_at' => $created,
            ]);

            if ($d['hero']) {
                DB::table('store_landing_pages')->insert([
                    'store_id' => $id, 'hero_image' => $d['hero'], 'tagline' => $d['tagline'],
                    'about_text' => $d['description'] . ' Our event coordinators work with you from the first visit to the last guest leaving, so you can enjoy your day.',
                    'services' => json_encode([
                        ['title' => 'In-house Catering', 'description' => 'Pakistani, Chinese and continental menus prepared fresh by our own chefs.'],
                        ['title' => 'Decor & Stage', 'description' => 'Floral stages, lighting and theme decor designed to your colours.'],
                        ['title' => 'Event Coordination', 'description' => 'A dedicated coordinator for timings, vendors and guest flow.'],
                        ['title' => 'Valet Parking & Security', 'description' => 'Trained valet staff and security for every event.'],
                    ]),
                    'faq' => json_encode([
                        ['question' => 'How far in advance should I book?', 'answer' => 'For wedding season (November to February) we recommend booking 2–3 months ahead. Off-season dates are often available within a few weeks.'],
                        ['question' => 'Can we bring our own caterer?', 'answer' => 'Outside catering is allowed for some venues with a service charge. Please ask us when you book.'],
                        ['question' => 'What is the cancellation policy?', 'answer' => 'Cancellations more than 30 days before the event get a full refund. Within 30 days, 50% is refunded.'],
                        ['question' => 'Is there a backup generator?', 'answer' => 'Yes, every venue has full generator backup so load-shedding never interrupts your event.'],
                    ]),
                    'social_links' => json_encode($social),
                    'testimonials' => json_encode([
                        ['name' => 'Sadia & Imran', 'quote' => 'Everything from the stage to the food was perfect. Our guests are still talking about it!', 'rating' => 5],
                        ['name' => 'Nadeem Akhtar', 'quote' => 'Very professional team. They handled 700 guests without a single problem.', 'rating' => 5],
                        ['name' => 'Rabia Shah', 'quote' => 'Beautiful venue and fair pricing. Parking was a little tight but staff managed it well.', 'rating' => 4],
                    ]),
                    'gallery' => json_encode($d['gallery']),
                    'created_at' => $created, 'updated_at' => $created,
                ]);
            }

            $stores[$key] = ['id' => $id, 'name' => $d['name'], 'owner' => $owner, 'city' => $d['city'],
                'state' => $d['state'], 'lat' => $d['lat'], 'lng' => $d['lng'], 'created_at' => $created];
        }

        return $stores;
    }

    // ------------------------------------------------------------------ venues

    private function seedVenues(array $stores): array
    {
        $wedding    = ['weddings', 'marriage', 'engagement_parties', 'bridal_showers', 'anniversaries'];
        $party      = ['birthday_parties', 'baby_showers', 'graduation_parties', 'anniversaries', 'engagement_parties'];
        $corporate  = ['corporate_events', 'conferences', 'seminars_workshops', 'meetings'];
        $concert    = ['concerts_music_events', 'festivals', 'corporate_events'];

        $defs = [
            ['shalimar', 'Shalimar Grand Ballroom', 'Gulberg III', 300, 1000, 22000, 2, 4500, '12:00', '23:59', true, $wedding, ['01', '29', '25', '24'],
                'Our flagship crystal-chandelier ballroom with a 60-foot bridal stage, separate family lounge and a VIP entrance for the baraat. Fully air-conditioned and seats up to 1,000 guests at round tables.'],
            ['shalimar', 'Mughal Garden Marquee', 'Canal Bank Road', 200, 700, 30000, 1, 3800, '16:00', '23:59', true, $wedding, ['06', '15', '30', '19'],
                'An open-air garden marquee inspired by the Shalimar Gardens, with fountains, fairy-light walkways and a covered dining area. Ideal for mehndi and walima evenings.'],
            ['shalimar', 'Royal Crystal Hall', 'DHA Phase 5', 100, 400, 9000, 1, 5200, '12:00', '23:30', false, array_merge($wedding, ['religious_events']), ['30', '02', '03'],
                'An intimate, luxurious hall for nikkah ceremonies, engagements and smaller receptions. Mirror-work ceilings and a dedicated bridal room.'],

            ['karachi', 'Seaview Convention Hall', 'Clifton Block 4', 100, 800, 18000, 1, 2500, '08:00', '22:00', true, $corporate, ['11', '09', '10'],
                'A column-free convention hall with stage, LED wall, simultaneous-interpretation booths and high-speed Wi-Fi. Can be split into three breakout rooms.'],
            ['karachi', 'Clifton Rooftop Lounge', 'Clifton Block 5', 30, 150, 4500, 1, 3200, '17:00', '23:59', false, $party, ['17', '14', '12'],
                'A rooftop terrace with sea breeze, string lights and a live BBQ station. Perfect for birthdays, graduation parties and sundowners.'],
            ['karachi', 'Harbour Arena', 'Karsaz Road', 500, 3000, 45000, 1, 1800, '15:00', '23:59', true, $concert, ['04', '16', '27', '08'],
                'Karachi\'s go-to indoor arena for concerts and festivals: professional line-array sound, moving-head lighting rig and a 40-foot stage.'],

            ['margalla', 'Margalla Hills Pavilion', 'Pir Sohawa Road', 150, 600, 25000, 1, 5500, '15:00', '23:00', true, $wedding, ['15', '23', '06', '28'],
                'A hillside pavilion with panoramic views of Islamabad. Sunset nikkah ceremonies on the lawn followed by dinner under the stars.'],
            ['margalla', 'Capital Conference Centre', 'Blue Area', 50, 400, 12000, 2, 2200, '08:00', '21:00', false, $corporate, ['10', '09', '11'],
                'Business-class conference centre with a 400-seat auditorium, two boardrooms and catering for working lunches. Walking distance from major hotels.'],
            ['margalla', 'The Daman Terrace', 'F-7 Markaz', 20, 120, 3500, 1, 4000, '12:00', '23:00', false, $party, ['13', '24', '03'],
                'A fine-dining terrace for engagements, anniversaries and private dinners, with a chef\'s tasting menu and live acoustic music.'],

            ['lyallpur', 'Clock Tower Marquee', 'Canal Road', 200, 900, 26000, 1, 2800, '12:00', '23:59', true, $wedding, ['25', '02', '24'],
                'A spacious, fully air-conditioned marquee with a royal stage and separate ladies\' and gents\' seating options.'],
            ['lyallpur', 'Canal View Party Hall', 'Jinnah Colony', 50, 250, 6000, 1, 2200, '11:00', '23:00', false, $party, ['12', '17', '03'],
                'A colourful party hall with play area, photo booth and themed decor packages for birthdays and baby showers.'],

            ['multan', 'Sultan Palace Banquet', 'Bosan Road', 250, 1200, 28000, 2, 3200, '12:00', '23:59', true, $wedding, ['29', '01', '30', '21'],
                'A palace-style banquet with hand-painted Multani tile work, two halls that can be combined, and in-house sohan halwa dessert stations.'],
            ['multan', 'Neon Club Arena', 'Gulgasht Colony', 200, 1500, 20000, 1, 1500, '16:00', '23:59', false, $concert, ['22', '18', '08', '26'],
                'An indoor concert arena with LED tunnel entrance, DJ booth and VIP balcony. Home of Multan\'s biggest music nights.'],

            ['pindi', 'Saddar Royal Hall', 'Murree Road', 200, 800, 20000, 1, 3000, '12:00', '23:59', true, $wedding, ['02', '25', '29'],
                'A classic Rawalpindi wedding hall with a grand entrance, chandelier-lit main hall and separate family seating.'],
            ['pindi', 'Bahria Garden Lawn', 'Bahria Town Phase 7', 150, 600, 28000, 1, 3600, '16:00', '23:59', false, $wedding, ['06', '15', '23'],
                'An open lawn with a covered dining marquee, fountain backdrop and evening lighting, ideal for mehndi and nikkah.'],
            ['pindi', 'Kids Carnival Hall', 'Commercial Market', 40, 200, 5000, 1, 2000, '11:00', '22:00', false, $party, ['12', '17', '14'],
                'A bright, fun hall for birthdays and baby showers, with balloon decor, games area and a magician on request.'],
            ['peshawar', 'Khyber Grand Hall', 'University Road', 300, 1200, 30000, 2, 2900, '12:00', '23:30', true, $wedding, ['30', '01', '29', '21'],
                'Peshawar\'s largest wedding hall, with two floors that can host separate ladies\' and gents\' functions at the same time.'],
            ['peshawar', 'Tehkal Conference Centre', 'Tehkal', 50, 350, 9000, 1, 2000, '08:00', '21:00', false, $corporate, ['10', '11', '09'],
                'A modern conference centre with a 350-seat hall, breakout rooms and high-speed internet for corporate and NGO events.'],
            ['peshawar', 'Chapli Courtyard', 'Hayatabad Phase 3', 30, 180, 4000, 1, 3400, '12:00', '23:00', false, $party, ['13', '24', '03'],
                'An open courtyard restaurant venue serving Peshawari food, perfect for engagements, anniversaries and family dinners.'],
        ];

        $amenitySets = [
            'Air Conditioning' => ['snowflake', false, null],
            'Valet Parking'    => ['car', false, null],
            'Generator Backup' => ['zap', false, null],
            'Wi-Fi'            => ['wifi', false, null],
            'Bridal Room'      => ['sparkles', false, null],
            'Prayer Area'      => ['moon', false, null],
            'Wheelchair Access'=> ['accessibility', false, null],
            'Sound System'     => ['speaker', true, 25000],
            'Stage Decoration' => ['flower', true, 60000],
            'Photography'      => ['camera', true, 45000],
            'Projector & Screen' => ['projector', true, 15000],
            'LED Wall'         => ['monitor', true, 80000],
            'DJ Setup'         => ['music', true, 35000],
            'Fireworks'        => ['flame', true, 50000],
            'Live BBQ Station' => ['flame', true, 30000],
        ];
        $amenitiesFor = fn (array $types) => match (true) {
            in_array('weddings', $types)       => ['Air Conditioning', 'Valet Parking', 'Generator Backup', 'Bridal Room', 'Prayer Area', 'Sound System', 'Stage Decoration', 'Photography', 'Fireworks'],
            in_array('conferences', $types)    => ['Air Conditioning', 'Wi-Fi', 'Generator Backup', 'Wheelchair Access', 'Prayer Area', 'Projector & Screen', 'LED Wall', 'Sound System', 'Photography'],
            in_array('concerts_music_events', $types) => ['Air Conditioning', 'Valet Parking', 'Generator Backup', 'Wheelchair Access', 'Sound System', 'LED Wall', 'DJ Setup', 'Photography'],
            default                            => ['Air Conditioning', 'Wi-Fi', 'Generator Backup', 'Sound System', 'Stage Decoration', 'Photography', 'DJ Setup', 'Live BBQ Station'],
        };

        $venues = [];
        // Bookings validate event_type against active, non-deleted category slugs, so a
        // venue may only offer those (an admin may have deleted some, e.g. "marriage").
        $activeSlugs = DB::table('categories')->where('is_active', true)->whereNull('deleted_at')->pluck('slug')->all();

        foreach ($defs as [$storeKey, $name, $area, $capMin, $capMax, $sqft, $floors, $perHead, $open, $close, $featured, $types, $images, $desc]) {
            $types = array_values(array_intersect($types, $activeSlugs)) ?: array_slice($activeSlugs, 0, 3);
            $store   = $stores[$storeKey];
            $slug    = Str::slug($name);
            $created = $store['created_at']->copy()->addDays(mt_rand(1, 10));

            $id = DB::table('venues')->insertGetId([
                'store_id' => $store['id'], 'name' => $name, 'slug' => $slug, 'description' => $desc,
                'thumbnail' => "demo/{$images[0]}.jpg",
                'address' => "{$area}, {$store['city']}", 'city' => $store['city'], 'state' => $store['state'],
                'country' => 'Pakistan',
                'latitude' => round($store['lat'] + mt_rand(-200, 200) / 10000, 8),
                'longitude' => round($store['lng'] + mt_rand(-200, 200) / 10000, 8),
                'capacity_min' => $capMin, 'capacity_max' => $capMax, 'area_sqft' => $sqft, 'floors' => $floors,
                'price_per_head' => $perHead, 'pricing_type' => 'per_head', 'dynamic_pricing' => false,
                'event_types' => json_encode($types),
                'operating_start' => $open, 'operating_end' => $close,
                'status' => 'active', 'is_featured' => $featured,
                'created_at' => $created, 'updated_at' => $created,
            ]);

            foreach ($images as $n => $img) {
                DB::table('venue_images')->insert([
                    'venue_id' => $id, 'path' => "demo/{$img}.jpg", 'alt_text' => $name,
                    'is_primary' => $n === 0, 'sort_order' => $n,
                    'created_at' => $created, 'updated_at' => $created,
                ]);
            }

            $paidAmenities = [];
            foreach ($amenitiesFor($types) as $aName) {
                [$icon, $isPaid, $price] = $amenitySets[$aName];
                $aid = DB::table('venue_amenities')->insertGetId([
                    'venue_id' => $id, 'name' => $aName, 'icon' => $icon, 'is_paid' => $isPaid, 'price' => $price,
                    'created_at' => $created, 'updated_at' => $created,
                ]);
                if ($isPaid) {
                    $paidAmenities[] = ['id' => $aid, 'name' => $aName, 'price' => $price];
                }
            }

            $venues[] = ['id' => $id, 'name' => $name, 'store' => $store, 'cap_min' => $capMin, 'cap_max' => $capMax,
                'price_per_head' => $perHead, 'types' => $types, 'open' => $open, 'close' => $close,
                'paid_amenities' => $paidAmenities, 'created_at' => $created];
        }

        return $venues;
    }

    // ------------------------------------------------------------------ bookings, payments, reviews

    private function seedBookings(array $venues, array $clients): void
    {
        $eventNames = [
            'weddings' => ['%s Wedding Reception', 'Baraat of %s', '%s Shaadi'],
            'marriage' => ['Walima of %s', 'Nikkah Ceremony – %s'],
            'engagement_parties' => ['%s Engagement', 'Mangni of %s'],
            'bridal_showers' => ['%s Mehndi Night', 'Dholki for %s'],
            'anniversaries' => ['%s 25th Anniversary', 'Anniversary Dinner – %s'],
            'religious_events' => ['Milad Gathering – %s Family'],
            'birthday_parties' => ['%s\'s Birthday Bash', 'Surprise Party for %s'],
            'baby_showers' => ['%s Baby Shower'],
            'graduation_parties' => ['%s Graduation Party'],
            'corporate_events' => ['Annual Dinner – %s & Co', 'Product Launch by %s Tech'],
            'conferences' => ['Pakistan Tech Summit', 'Annual Sales Conference', 'HealthCare Pakistan Expo'],
            'seminars_workshops' => ['Digital Marketing Workshop', 'Startup Founders Bootcamp'],
            'meetings' => ['Board Meeting – %s Holdings', 'Quarterly Review – %s Group'],
            'concerts_music_events' => ['Sufi Night Live', 'Coke Studio Tribute Concert', 'Indie Music Fest'],
            'festivals' => ['Winter Food Festival', 'Basant Spring Festival'],
        ];
        $requirements = [
            null, null,
            'Need a separate seating area for ladies.',
            'Please arrange a vegetarian menu for about 30 guests.',
            'Wheelchair access needed for two elderly guests.',
            'We would like red and gold stage decor.',
            'Please keep the sound system low after 11 PM.',
            'Requesting extra parking for 50 cars.',
        ];
        $cancelReasons = ['Event postponed due to family emergency.', 'Changed the city of the event.', 'Found a venue closer to home.'];
        $rejectReasons = ['Venue already reserved for a private event on this date.', 'Requested guest count exceeds what we can serve on this date.'];
        $reviewTexts = [
            5 => [['Absolutely perfect!', 'The hall looked stunning and the food was delicious. Staff were polite and everything ran on time.'],
                  ['Best decision we made', 'From booking to the last guest leaving, the team was amazing. Highly recommended.'],
                  ['Exceeded expectations', 'Beautiful decor, great sound and excellent service. Our guests loved it.']],
            4 => [['Great venue', 'Lovely place and good food. Parking got a bit crowded but the valet handled it.'],
                  ['Very good experience', 'Nice ambience and helpful staff. Air conditioning could have been a little stronger.'],
                  ['Would book again', 'Value for money and the coordinator was very responsive on WhatsApp.']],
            3 => [['Decent but room to improve', 'The venue is nice but the service was slow during dinner. Decor was as promised.'],
                  ['Average', 'Okay for the price. Generator took a minute to start during load-shedding.']],
        ];
        $storeReplies = [
            'Thank you so much for choosing us! It was a pleasure hosting your event.',
            'We are glad you enjoyed it. Looking forward to hosting you again!',
            'Thanks for the honest feedback — we have shared it with our team and are working on it.',
        ];

        $rate   = (float) config('payfast.pkr_to_zar_rate', 0.065);
        $used   = [];   // venue_id|date => true (keeps one booking per venue per day)
        $notifs = [];
        $slots  = [['12:00', '16:00'], ['13:00', '17:00'], ['18:00', '23:00'], ['19:00', '23:30'], ['17:00', '22:00'], ['10:00', '14:00']];

        $plan = [];
        // ~ TOTAL_BOOKINGS bookings: past 12 months to next 4 months, busier in wedding season
        while (count($plan) < self::TOTAL_BOOKINGS) {
            $offset = mt_rand(-365, 120);
            $month  = (int) $this->now->copy()->addDays($offset)->format('n');
            if (!in_array($month, [11, 12, 1, 2, 3]) && mt_rand(1, 100) <= 35) {
                continue; // off-season: fewer events
            }
            $plan[] = $offset;
        }
        sort($plan);

        $methods = ['payd_fast', 'payd_fast', 'payd_fast', 'payd_fast', 'payd_fast', 'payd_fast', 'payd_fast', 'bank_transfer', 'bank_transfer', 'cash'];

        foreach ($plan as $offset) {
            $venue = $venues[array_rand($venues)];
            if ($offset < 0 && $this->now->copy()->addDays($offset)->lt($venue['created_at'])) {
                $offset = mt_rand(-20, 120);
            }
            $date = $this->now->copy()->addDays($offset)->startOfDay();
            $key  = $venue['id'] . '|' . $date->toDateString();
            if (isset($used[$key]) || $offset === 0) {
                continue;
            }
            $used[$key] = true;

            // Pick a client who had an account before the booking was made.
            $eligible = array_values(array_filter($clients, fn ($c) => $c['created_at']->lt($date->copy()->subDays(10))));
            $client   = $eligible ? $eligible[array_rand($eligible)] : $clients[array_rand($clients)];
            $type  = $venue['types'][array_rand($venue['types'])];
            $tpl    = $eventNames[$type][array_rand($eventNames[$type])];
            $who    = explode(' ', $client['name']);
            $eventName = sprintf($tpl, str_contains($tpl, 'Family') || str_contains($tpl, 'Co') || str_contains($tpl, 'Tech')
                || str_contains($tpl, 'Holdings') || str_contains($tpl, 'Group') ? end($who) : $who[0]);

            $guests = (int) (round(mt_rand($venue['cap_min'], $venue['cap_max']) / 10) * 10) ?: $venue['cap_min'];
            [$start, $end] = $slots[array_rand($slots)];
            if ($start < $venue['open']) {
                [$start, $end] = [$venue['open'], min('23:59', Carbon::parse($venue['open'])->addHours(4)->format('H:i'))];
            }
            $hours = (int) round(Carbon::parse($start)->diffInMinutes(Carbon::parse($end)) / 60);

            $picked = collect($venue['paid_amenities'])->shuffle()->take(mt_rand(0, 3));
            $base   = round($venue['price_per_head'] * $guests, 2);
            $amen   = round($picked->sum('price'), 2);
            $tax    = round(($base + $amen) * 0.05, 2);
            $total  = round($base + $amen + $tax, 2);

            // Status depends on whether the date is past or future.
            $r = mt_rand(1, 100);
            if ($offset < 0) {
                $status = $r <= 80 ? 'completed' : ($r <= 93 ? 'cancelled' : 'rejected');
            } else {
                $status = $r <= 45 ? 'pending' : ($r <= 90 ? 'confirmed' : 'cancelled');
            }

            $createdAt = $date->copy()->subDays(mt_rand(7, 60))->setTime(mt_rand(9, 22), mt_rand(0, 59));
            if ($createdAt->gt($this->now)) {
                $createdAt = $this->now->copy()->subHours(mt_rand(2, 72));
            }
            if ($createdAt->lt($client['created_at'])) {
                $createdAt = $client['created_at']->copy()->addHours(3);
            }

            $confirmedAt = in_array($status, ['confirmed', 'completed']) ? $createdAt->copy()->addHours(mt_rand(2, 48)) : null;
            $paid = $status === 'completed' || ($status === 'confirmed' && mt_rand(1, 100) <= 70);
            $paidAt = $paid ? ($confirmedAt ?? $createdAt)->copy()->addHours(mt_rand(1, 36)) : null;
            if ($paidAt && $paidAt->gt($this->now)) {
                $paidAt = $this->now->copy()->subMinutes(mt_rand(10, 600));
            }
            $refunded = $status === 'cancelled' && mt_rand(1, 100) <= 40;
            $cancelledAt = $status === 'cancelled' ? min($this->now, $createdAt->copy()->addDays(mt_rand(1, 6))) : null;
            if ($refunded) {
                $paidAt = $createdAt->copy()->addHours(mt_rand(3, 20));
                $cancelledAt = $paidAt->copy()->addDays(1);
            }
            if ($cancelledAt && $cancelledAt->gt($this->now)) {
                $cancelledAt = $this->now->copy()->subHours(1);
            }
            $updatedAt = max(array_filter([$createdAt, $confirmedAt, $paidAt, $cancelledAt]));
            if ($status === 'completed') {
                $updatedAt = $date->copy()->addDay()->setTime(2, 0);
            }

            $bookingId = DB::table('bookings')->insertGetId([
                'booking_number' => 'BK-' . strtoupper(substr(md5('demo' . $venue['id'] . $date->toDateString()), 0, 13)),
                'venue_id' => $venue['id'], 'client_id' => $client['id'], 'store_id' => $venue['store']['id'],
                'event_name' => $eventName, 'event_type' => $type,
                'special_requirements' => $requirements[array_rand($requirements)],
                'expected_guests' => $guests, 'event_date' => $date->toDateString(),
                'start_time' => $start . ':00', 'end_time' => $end . ':00', 'duration_hours' => $hours,
                'base_price' => $base, 'amenities_price' => $amen, 'discount_amount' => 0,
                'tax_amount' => $tax, 'total_amount' => $total,
                'status' => $status,
                'payment_status' => $refunded ? 'refunded' : ($paid ? 'paid' : 'unpaid'),
                'paid_at' => $paidAt,
                'cancellation_reason' => $status === 'cancelled' ? $cancelReasons[array_rand($cancelReasons)] : null,
                'rejection_reason' => $status === 'rejected' ? $rejectReasons[array_rand($rejectReasons)] : null,
                'confirmed_at' => $confirmedAt, 'cancelled_at' => $cancelledAt,
                'created_at' => $createdAt, 'updated_at' => $updatedAt,
            ]);

            // Unsuccessful PayFast attempts: before a successful payment, or on unpaid bookings.
            $attemptBase = $paidAt ?? $confirmedAt ?? $createdAt;
            $failedAttempts = ($paid || $refunded) ? (mt_rand(1, 100) <= 18 ? 1 : 0)
                : (in_array($status, ['confirmed', 'pending', 'cancelled']) && mt_rand(1, 100) <= 35 ? mt_rand(1, 2) : 0);
            for ($a = 0; $a < $failedAttempts; $a++) {
                $at = $attemptBase->copy()->subHours(($failedAttempts - $a) * mt_rand(1, 5));
                if ($at->lt($createdAt)) {
                    $at = $createdAt->copy()->addMinutes(15 + $a * 20);
                }
                if ($at->gt($this->now)) {
                    $at = $this->now->copy()->subMinutes(30 + $a * 20);
                }
                $failed = mt_rand(1, 100) <= 55;
                DB::table('payments')->insert([
                    'booking_id' => $bookingId, 'client_id' => $client['id'], 'transaction_id' => null,
                    'payment_reference' => 'EG-' . $bookingId . '-DEMO-' . strtoupper(Str::random(8)),
                    'amount' => $total, 'currency' => 'PKR',
                    'gateway_amount' => round($total * $rate, 2), 'gateway_currency' => 'ZAR', 'exchange_rate' => $rate,
                    'payment_method' => 'payd_fast',
                    'status' => $failed ? 'failed' : 'cancelled',
                    'gateway_response' => json_encode(['payment_status' => $failed ? 'FAILED' : 'CANCELLED', 'demo' => true]),
                    'notes' => $failed ? ['Card declined by issuing bank.', 'Insufficient funds.', '3-D Secure verification failed.'][mt_rand(0, 2)]
                        : 'Customer cancelled on the PayFast page.',
                    'created_at' => $at, 'updated_at' => $at->copy()->addMinutes(mt_rand(2, 10)),
                ]);
            }

            if ($paid || $refunded) {
                $method  = $methods[array_rand($methods)];
                $gateway = $method === 'payd_fast';
                DB::table('payments')->insert([
                    'booking_id' => $bookingId, 'client_id' => $client['id'],
                    'transaction_id' => $gateway ? 'PF' . mt_rand(10000000, 99999999) . $bookingId
                        : ($method === 'bank_transfer' ? 'IBFT' . mt_rand(100000000, 999999999) . $bookingId : null),
                    'payment_reference' => 'EG-' . $bookingId . '-DEMO-' . strtoupper(Str::random(8)),
                    'amount' => $total, 'currency' => 'PKR',
                    'gateway_amount' => $gateway ? round($total * $rate, 2) : null,
                    'gateway_currency' => $gateway ? 'ZAR' : null,
                    'exchange_rate' => $gateway ? $rate : null,
                    'payment_method' => $method,
                    'status' => $refunded ? 'refunded' : 'completed',
                    'gateway_response' => $gateway ? json_encode(['payment_status' => 'COMPLETE', 'demo' => true]) : null,
                    'paid_at' => $paidAt, 'refunded_at' => $refunded ? $cancelledAt : null,
                    'notes' => $refunded ? 'Refunded after cancellation.'
                        : ($method === 'bank_transfer' ? 'Paid via bank transfer (Meezan Bank).' : ($method === 'cash' ? 'Cash received at venue office.' : null)),
                    'created_at' => $paidAt->copy()->subMinutes(5), 'updated_at' => $refunded ? $cancelledAt : $paidAt,
                ]);
            }

            $data = ['booking_id' => $bookingId, 'venue_name' => $venue['name']];
            $notifs[] = [$venue['store']['owner']['id'], 'new_booking_received', 'New Booking Received',
                "{$client['name']} has booked \"{$venue['name']}\" for {$date->toDateString()}.", $data, $createdAt];
            $notifs[] = [$client['id'], 'booking_created', 'Booking Submitted',
                "Your booking for \"{$venue['name']}\" on {$date->toDateString()} has been submitted and is pending confirmation.", $data, $createdAt];
            if ($confirmedAt) {
                $notifs[] = [$client['id'], 'booking_confirmed', 'Booking Confirmed!',
                    "Great news! Your booking for \"{$venue['name']}\" has been confirmed by {$venue['store']['name']}.", $data, $confirmedAt];
            }
            if ($status === 'rejected') {
                $notifs[] = [$client['id'], 'booking_rejected', 'Booking Rejected',
                    "Sorry, your booking for \"{$venue['name']}\" was declined by {$venue['store']['name']}.", $data, $createdAt->copy()->addHours(5)];
            }
            if ($paidAt && !$refunded) {
                $notifs[] = [$venue['store']['owner']['id'], 'payment_received', 'Payment Received',
                    "Payment of PKR " . number_format($total) . " received for \"{$venue['name']}\".", $data, $paidAt];
            }

            // Reviews for most completed bookings
            if ($status === 'completed' && mt_rand(1, 100) <= 80) {
                $rating = [5, 5, 5, 4, 4, 4, 3][mt_rand(0, 6)];
                [$title, $comment] = $reviewTexts[$rating][array_rand($reviewTexts[$rating])];
                $reviewedAt = $date->copy()->addDays(mt_rand(1, 10))->setTime(mt_rand(10, 22), mt_rand(0, 59));
                if ($reviewedAt->gt($this->now)) {
                    $reviewedAt = $this->now->copy()->subHours(mt_rand(1, 20));
                }
                $sub = fn () => max(1, min(5, $rating + mt_rand(-1, 1)));
                $reply = mt_rand(1, 100) <= 50;
                DB::table('reviews')->insert([
                    'venue_id' => $venue['id'], 'booking_id' => $bookingId, 'client_id' => $client['id'],
                    'rating' => $rating, 'title' => $title, 'comment' => $comment,
                    'cleanliness_rating' => $sub(), 'value_rating' => $sub(), 'service_rating' => $sub(), 'location_rating' => $sub(),
                    'is_approved' => mt_rand(1, 100) <= 92,
                    'store_reply' => $reply ? $storeReplies[$rating === 3 ? 2 : mt_rand(0, 1)] : null,
                    'store_replied_at' => $reply ? min($this->now, $reviewedAt->copy()->addDay()) : null,
                    'created_at' => $reviewedAt, 'updated_at' => $reviewedAt,
                ]);
                $notifs[] = [$venue['store']['owner']['id'], 'new_review', 'New Review',
                    "{$client['name']} left a {$rating}-star review for \"{$venue['name']}\".", $data, $reviewedAt];
            }
        }

        // Store approval notifications for owners of approved stores
        foreach (array_unique(array_map(fn ($v) => $v['store']['id'], $venues)) as $storeId) {
            $s = collect($venues)->firstWhere('store.id', $storeId)['store'];
            $notifs[] = [$s['owner']['id'], 'store_approved', 'Store Approved!',
                "Congratulations! Your store \"{$s['name']}\" has been approved. You can now list venues and accept bookings.",
                ['store_id' => $storeId, 'store_name' => $s['name']], $s['created_at']->copy()->addHours(6)];
        }

        $rows = [];
        foreach ($notifs as [$userId, $type, $title, $body, $data, $at]) {
            $isRead = $at->lt($this->now->copy()->subDays(3)) || mt_rand(1, 100) <= 30;
            $rows[] = [
                'user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body,
                'data' => json_encode($data), 'is_read' => $isRead, 'read_at' => $isRead ? min($this->now, $at->copy()->addHours(4)) : null,
                'created_at' => $at, 'updated_at' => $at,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
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

    // ------------------------------------------------------------------ images

    /** Generates a coloured square with initials; returns the public-disk path. */
    private function makeMonogram(string $path, string $name, int $size, int $seed, ?array $rgb = null): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $full = storage_path('app/public/' . $path);
        @mkdir(dirname($full), 0775, true);

        $palette = [[176, 58, 46], [21, 101, 132], [46, 125, 50], [123, 31, 162], [191, 120, 20], [0, 121, 107], [194, 24, 91], [69, 90, 100]];
        [$r, $g, $b] = $rgb ?? $palette[$seed % count($palette)];

        $im = imagecreatetruecolor($size, $size);
        imagefilledrectangle($im, 0, 0, $size, $size, imagecolorallocate($im, $r, $g, $b));
        $white = imagecolorallocate($im, 255, 255, 255);

        $words    = array_values(array_filter(preg_split('/\s+/', $name), fn ($w) => ctype_alpha($w[0] ?? '')));
        $initials = strtoupper(substr($words[0] ?? 'E', 0, 1) . substr($words[1] ?? '', 0, 1));
        $font     = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

        if (is_file($font)) {
            $pt  = $size * 0.32;
            $box = imagettfbbox($pt, 0, $font, $initials);
            $x   = (int) (($size - ($box[2] - $box[0])) / 2 - $box[0]);
            $y   = (int) (($size - ($box[1] - $box[7])) / 2 - $box[7]);
            imagettftext($im, $pt, 0, $x, $y, $white, $font, $initials);
        } else {
            imagestring($im, 5, (int) ($size / 2 - 8), (int) ($size / 2 - 8), $initials, $white);
        }

        imagepng($im, $full);
        imagedestroy($im);

        return $path;
    }
}
