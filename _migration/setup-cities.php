<?php
/**
 * Create the four city landing pages (car-rental-{city}).
 * Run: wp eval-file /migration/setup-cities.php
 * Idempotent by slug. Content is unique per city (NOT a doorway template) —
 * English drafts; get ka/ru natively proofed before heavy promotion.
 *
 * After running this the FIRST time, flush rewrites so the pretty URLs resolve:
 *   wp rewrite flush
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

function glc_city_p( string $t ): string { return "<!-- wp:paragraph --><p>{$t}</p><!-- /wp:paragraph -->"; }
function glc_city_h( string $t ): string { return "<!-- wp:heading --><h2 class=\"wp-block-heading\">{$t}</h2><!-- /wp:heading -->"; }
function glc_city_ul( array $items ): string {
	$li = implode( '', array_map( fn( $i ) => "<!-- wp:list-item --><li>{$i}</li><!-- /wp:list-item -->", $items ) );
	return "<!-- wp:list --><ul class=\"wp-block-list\">{$li}</ul><!-- /wp:list -->";
}

/**
 * @param array{slug:string,title:string,excerpt:string,airport_name:string,airport_code:string,delivery:string,order:int,body:string} $c
 */
function glc_upsert_city( array $c ): void {
	$existing = get_posts( [ 'post_type' => 'city', 'name' => $c['slug'], 'posts_per_page' => 1, 'post_status' => 'any' ] );
	$id = wp_insert_post( [
		'ID'           => $existing[0]->ID ?? 0,
		'post_type'    => 'city',
		'post_status'  => 'publish',
		'post_name'    => $c['slug'],
		'post_title'   => $c['title'],
		'post_excerpt' => $c['excerpt'],
		'post_content' => $c['body'],
		'menu_order'   => $c['order'],
	] );
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "  ✗ {$c['slug']}: " . $id->get_error_message() );
		return;
	}
	update_post_meta( $id, 'glc_airport_name', $c['airport_name'] );
	update_post_meta( $id, 'glc_airport_code', $c['airport_code'] );
	update_post_meta( $id, 'glc_delivery_note', $c['delivery'] );
	WP_CLI::log( "  ✓ /car-rental-{$c['slug']}/  (#{$id})" );
}

/* ----- Unique, genuinely differentiated content per city ------------------ */

$cities = [
	[
		'slug' => 'tbilisi', 'order' => 1,
		'title' => 'Car Rental in Tbilisi',
		'excerpt' => 'Rent a 4x4 in Tbilisi with free delivery to Tbilisi International Airport (TBS). Full insurance, no deposit, pay at pickup.',
		'airport_name' => 'Tbilisi International', 'airport_code' => 'TBS',
		'delivery' => 'Free delivery to Tbilisi Airport & city hotels',
		'body' =>
			glc_city_p( 'Tbilisi is where most Georgia road trips begin, and it is Geolander\'s home base. We deliver your car free of charge to Tbilisi International Airport (TBS) or to any hotel or address in the city — usually within the hour once your dates are confirmed on WhatsApp. Every rental is a real 4x4 with full insurance, unlimited mileage, and no security deposit; you pay at pickup, not online.' ) .
			glc_city_h( 'Where a Tbilisi rental takes you' ) .
			glc_city_p( 'From Tbilisi the whole country is within a day\'s drive, and a proper 4x4 opens the routes an ordinary car cannot:' ) .
			glc_city_ul( [
				'<strong>Kazbegi &amp; Gudauri</strong> — the Georgian Military Highway north to Gergeti Trinity Church (about 3 hours).',
				'<strong>Kakheti wine country</strong> — Sighnaghi, Telavi and the Alazani Valley to the east.',
				'<strong>Davit Gareja</strong> — the desert cave monastery on the Azerbaijan border, where the last gravel stretch really wants 4x4.',
				'<strong>Tusheti</strong> — for the experienced: the Abano Pass, one of the world\'s most demanding mountain roads (summer only, ask us first).',
			] ) .
			glc_city_h( 'Driving in and out of Tbilisi' ) .
			glc_city_p( 'City traffic is busy and parking near the old town is tight, so many travellers collect the car on the day they leave for the mountains rather than for city sightseeing. Tell us your plan on WhatsApp and we will suggest the pickup timing that saves you a parking headache.' ),
	],
	[
		'slug' => 'batumi', 'order' => 2,
		'title' => 'Car Rental in Batumi',
		'excerpt' => 'Rent a 4x4 in Batumi with free delivery to Batumi Airport (BUS) and seafront hotels. Full insurance, no deposit.',
		'airport_name' => 'Batumi International', 'airport_code' => 'BUS',
		'delivery' => 'Free delivery to Batumi Airport & seafront hotels',
		'body' =>
			glc_city_p( 'Batumi is Georgia\'s Black Sea capital — palm-lined boulevards, summer nightlife, and mountains that rise straight off the coast. We deliver your car free to Batumi International Airport (BUS) or to your seafront hotel, so you can land, settle in, and pick the car up only when you actually want to drive.' ) .
			glc_city_h( 'Why you want 4x4 in Adjara' ) .
			glc_city_p( 'The coast is flat and easy, but the reason to rent in Batumi is what sits just behind it — the green, rainy mountains of Adjara, where the interesting roads turn to gravel fast:' ) .
			glc_city_ul( [
				'<strong>Mtirala National Park</strong> — rainforest, waterfalls and a propeller bridge, 25 km inland.',
				'<strong>Machakhela valley</strong> — old arched stone bridges strung along the Turkish border.',
				'<strong>Gonio &amp; Sarpi</strong> — the Roman fortress and the beach right on the frontier.',
				'<strong>Goderdzi Pass</strong> — the spectacular high road over to Akhaltsikhe and southern Georgia.',
			] ) .
			glc_city_h( 'Batumi in summer' ) .
			glc_city_p( 'July and August are peak season on the coast, cars go quickly, and prices are seasonal — message us early on WhatsApp to lock a vehicle and an exact price for your dates.' ),
	],
	[
		'slug' => 'kutaisi', 'order' => 3,
		'title' => 'Car Rental at Kutaisi Airport',
		'excerpt' => 'Pick up a 4x4 at Kutaisi Airport (KUT) — the budget-flight gateway. Free airport delivery, full insurance, no deposit.',
		'airport_name' => 'Kutaisi International', 'airport_code' => 'KUT',
		'delivery' => 'Free pickup at Kutaisi Airport arrivals',
		'body' =>
			glc_city_p( 'Kutaisi International Airport (KUT) is how most budget travellers reach Georgia — the Wizz Air and low-cost hub for the whole region. The catch is that the airport sits well outside the city with little around it, so a car waiting at arrivals is the single best way to start your trip. We meet you at the terminal with the keys; full insurance, unlimited mileage, no deposit, pay on arrival.' ) .
			glc_city_h( 'Kutaisi is the gateway to western Georgia' ) .
			glc_city_p( 'Skip the taxi queues and drive straight into the west, which is greener, wilder and less visited than the east:' ) .
			glc_city_ul( [
				'<strong>Prometheus Cave &amp; Sataplia</strong> — huge caverns and dinosaur footprints, both a short drive from the airport.',
				'<strong>Okatse Canyon &amp; Kinchkha waterfall</strong> — cliff walkways and a two-tier falls in Imereti.',
				'<strong>Katskhi Pillar</strong> — the tiny church on top of a 40-metre limestone column.',
				'<strong>Svaneti</strong> — the big one: Mestia and Ushguli, medieval tower villages under the high Caucasus. The road rewards a capable 4x4.',
			] ) .
			glc_city_h( 'Landing late at Kutaisi?' ) .
			glc_city_p( 'Low-cost flights often arrive at odd hours. Send us your flight number on WhatsApp and we will have the car at the terminal whenever you land — no waiting for morning.' ),
	],
	[
		'slug' => 'kobuleti', 'order' => 4,
		'title' => 'Car Rental in Kobuleti',
		'excerpt' => 'Rent a 4x4 in Kobuleti with free delivery to your hotel. Quieter Black Sea base near Batumi. Full insurance, no deposit.',
		'airport_name' => 'Batumi International', 'airport_code' => 'BUS',
		'delivery' => 'Free delivery to Kobuleti hotels & guesthouses',
		'body' =>
			glc_city_p( 'Kobuleti is the calmer stretch of Georgia\'s Black Sea coast — a long pebble beach and low-key guesthouses, half an hour up from Batumi and close to Batumi Airport (BUS). It suits families and travellers who want the sea without Batumi\'s crowds, and a car turns a quiet beach base into a launch pad for the whole southwest. We deliver free to your hotel or guesthouse.' ) .
			glc_city_h( 'Day trips from Kobuleti' ) .
			glc_city_p( 'Everything in Adjara is within easy reach, plus a few things right on the doorstep:' ) .
			glc_city_ul( [
				'<strong>Kobuleti Protected Areas</strong> — the Ispani peat bogs and birdlife right beside town.',
				'<strong>Kintrishi Protected Area</strong> — lakes and forest in the hills just inland.',
				'<strong>Batumi</strong> — the boulevard, botanical garden and nightlife, a 30-minute drive south.',
				'<strong>Mtirala &amp; Machakhela</strong> — the Adjaran rainforest and border valleys, best reached with 4x4.',
			] ) .
			glc_city_h( 'Getting a car to Kobuleti' ) .
			glc_city_p( 'Fly into Batumi (BUS) or Kutaisi (KUT) and we will bring the car to you in Kobuleti, or meet you at either airport. Message us on WhatsApp with your arrival details for an exact price.' ),
	],
];

WP_CLI::log( 'Creating city landing pages…' );
foreach ( $cities as $city ) {
	glc_upsert_city( $city );
}

/* ---- ka + ru translations -------------------------------------------------
 * Written into the per-locale content fields GLC_Content reads (glc_title_ka,
 * glc_body_ka, …). English stays canonical; empty fields fall back to English.
 * Plain HTML (rendered via wpautop + wp_kses_post on the page). ka is a strong
 * draft — have a native speaker proof it before heavy promotion of /ka/ URLs.
 */
function glc_th( string $h ): string { return "<h2>{$h}</h2>"; }
function glc_tp( string $t ): string { return "<p>{$t}</p>"; }
function glc_tul( array $i ): string { return '<ul>' . implode( '', array_map( fn( $x ) => "<li>{$x}</li>", $i ) ) . '</ul>'; }

$trans = [
	'tbilisi' => [
		'ka' => [
			'title' => 'მანქანის ქირაობა თბილისში',
			'body'  =>
				glc_tp( 'თბილისი არის ის ადგილი, საიდანაც საქართველოში მოგზაურობა უმეტესად იწყება — და სწორედ აქ არის Geolander-ის ბაზა. მანქანას უფასოდ მოგაწვდით თბილისის საერთაშორისო აეროპორტში (TBS) ან ქალაქის ნებისმიერ სასტუმროსა თუ მისამართზე — ჩვეულებრივ ერთ საათში, მას შემდეგ რაც თარიღებს WhatsApp-ით დაადასტურებთ. ყველა მანქანა ნამდვილი 4x4-ია, სრული დაზღვევით, შეუზღუდავი გარბენით და დეპოზიტის გარეშე; გადახდა ხდება მანქანის მიღებისას, არა ონლაინ.' ) .
				glc_th( 'სად წაგიყვანთ თბილისში დაქირავებული მანქანა' ) .
				glc_tp( 'თბილისიდან მთელი ქვეყანა ერთი დღის სავალზეა, ხოლო კარგი 4x4 ხსნის იმ გზებს, რომლებზეც ჩვეულებრივი მანქანა ვერ ავა:' ) .
				glc_tul( [
					'<strong>ყაზბეგი და გუდაური</strong> — საქართველოს სამხედრო გზა ჩრდილოეთით, გერგეტის სამების ტაძრამდე (დაახლოებით 3 საათი).',
					'<strong>კახეთის ღვინის მხარე</strong> — სიღნაღი, თელავი და ალაზნის ველი აღმოსავლეთით.',
					'<strong>დავით გარეჯა</strong> — უდაბნოს გამოქვაბულების მონასტერი აზერბაიჯანის საზღვართან, სადაც ბოლო ხრეშიანი მონაკვეთი ნამდვილად საჭიროებს 4x4-ს.',
					'<strong>თუშეთი</strong> — გამოცდილთათვის: აბანოს უღელტეხილი, მსოფლიოს ერთ-ერთი ურთულესი მთის გზა (მხოლოდ ზაფხულში, წინასწარ გვკითხეთ).',
				] ) .
				glc_th( 'მართვა თბილისში და ქალაქიდან გასვლა' ) .
				glc_tp( 'ქალაქში მოძრაობა დატვირთულია და ძველ ქალაქთან პარკირება რთულია, ამიტომ ბევრი მოგზაური მანქანას იბარებს იმ დღეს, როცა მთებში მიემგზავრება, და არა ქალაქის დასათვალიერებლად. მოგვწერეთ თქვენი გეგმა WhatsApp-ით და შემოგთავაზებთ მიღების ისეთ დროს, რომ პარკირების თავის ტკივილი აგარიდოთ.' ),
		],
		'ru' => [
			'title' => 'Аренда автомобиля в Тбилиси',
			'body'  =>
				glc_tp( 'Тбилиси — отправная точка большинства путешествий по Грузии, и здесь же база Geolander. Мы бесплатно доставим автомобиль в Тбилисский международный аэропорт (TBS) или к любому отелю или адресу в городе — обычно в течение часа после подтверждения дат в WhatsApp. Каждая машина — настоящий внедорожник с полной страховкой, без ограничения пробега и без залога; оплата при получении, а не онлайн.' ) .
				glc_th( 'Куда вас увезёт машина из Тбилиси' ) .
				glc_tp( 'Из Тбилиси вся страна в пределах одного дня пути, а хороший внедорожник открывает маршруты, недоступные обычной машине:' ) .
				glc_tul( [
					'<strong>Казбеги и Гудаури</strong> — Военно-Грузинская дорога на север, к храму Гергети (около 3 часов).',
					'<strong>Винная Кахетия</strong> — Сигнахи, Телави и Алазанская долина на востоке.',
					'<strong>Давид Гареджа</strong> — пещерный монастырь в пустыне у границы с Азербайджаном; последний гравийный участок действительно требует внедорожника.',
					'<strong>Тушети</strong> — для опытных: перевал Абано, одна из самых сложных горных дорог в мире (только летом, спросите нас заранее).',
				] ) .
				glc_th( 'Вождение в Тбилиси и выезд из города' ) .
				glc_tp( 'Движение в городе плотное, а парковка у старого города тесная, поэтому многие берут машину в день выезда в горы, а не для осмотра города. Напишите нам план в WhatsApp, и мы подскажем время получения, которое избавит вас от проблем с парковкой.' ),
		],
	],
	'batumi' => [
		'ka' => [
			'title' => 'მანქანის ქირაობა ბათუმში',
			'body'  =>
				glc_tp( 'ბათუმი საქართველოს შავი ზღვის დედაქალაქია — პალმებით მოჩარჩოებული ბულვარები, ზაფხულის ღამის ცხოვრება და მთები, რომლებიც პირდაპირ სანაპიროდან იწყება. მანქანას უფასოდ მოგაწვდით ბათუმის საერთაშორისო აეროპორტში (BUS) ან თქვენს ზღვისპირა სასტუმროში, რომ ჩამოხვიდეთ, მოეწყოთ და მანქანა მაშინ აიღოთ, როცა მართლა დაგჭირდებათ.' ) .
				glc_th( 'რატომ გჭირდებათ 4x4 აჭარაში' ) .
				glc_tp( 'სანაპირო ბრტყელი და მარტივია, მაგრამ ბათუმში მანქანის დაქირავების მთავარი მიზეზი ის არის, რაც უკან დგას — აჭარის მწვანე, წვიმიანი მთები, სადაც საინტერესო გზები სწრაფად გადადის ხრეშზე:' ) .
				glc_tul( [
					'<strong>მტირალას ეროვნული პარკი</strong> — წვიმის ტყე, ჩანჩქერები და საკიდი ხიდი, 25 კმ სიღრმეში.',
					'<strong>მაჭახელას ხეობა</strong> — ძველი თაღოვანი ქვის ხიდები თურქეთის საზღვრის გასწვრივ.',
					'<strong>გონიო და სარფი</strong> — რომაული ციხე და პლაჟი პირდაპირ საზღვართან.',
					'<strong>გოდერძის უღელტეხილი</strong> — შესანიშნავი მაღალმთიანი გზა ახალციხისა და სამხრეთ საქართველოსკენ.',
				] ) .
				glc_th( 'ბათუმი ზაფხულში' ) .
				glc_tp( 'ივლისი და აგვისტო სანაპიროზე პიკის სეზონია, მანქანები სწრაფად იხარჯება და ფასები სეზონურია — მოგვწერეთ ადრე WhatsApp-ით, რომ დაჯავშნოთ მანქანა და ზუსტი ფასი თქვენს თარიღებზე.' ),
		],
		'ru' => [
			'title' => 'Аренда автомобиля в Батуми',
			'body'  =>
				glc_tp( 'Батуми — черноморская столица Грузии: бульвары в пальмах, летняя ночная жизнь и горы, поднимающиеся прямо от побережья. Мы бесплатно доставим машину в Батумский международный аэропорт (BUS) или к вашему отелю у моря — прилетайте, устраивайтесь и берите автомобиль тогда, когда действительно захотите ехать.' ) .
				glc_th( 'Зачем нужен внедорожник в Аджарии' ) .
				glc_tp( 'Побережье ровное и лёгкое, но главная причина брать машину в Батуми — то, что находится сразу за ним: зелёные дождливые горы Аджарии, где интересные дороги быстро переходят в гравий:' ) .
				glc_tul( [
					'<strong>Национальный парк Мтирала</strong> — дождевой лес, водопады и подвесной мост в 25 км от города.',
					'<strong>Долина Мачахела</strong> — старинные каменные арочные мосты вдоль турецкой границы.',
					'<strong>Гонио и Сарпи</strong> — римская крепость и пляж прямо на границе.',
					'<strong>Перевал Годердзи</strong> — впечатляющая высокогорная дорога в сторону Ахалцихе и южной Грузии.',
				] ) .
				glc_th( 'Батуми летом' ) .
				glc_tp( 'Июль и август — пик сезона на побережье, машины разбирают быстро, а цены сезонные. Напишите нам заранее в WhatsApp, чтобы забронировать автомобиль и точную цену на ваши даты.' ),
		],
	],
	'kutaisi' => [
		'ka' => [
			'title' => 'მანქანის ქირაობა ქუთაისის აეროპორტში',
			'body'  =>
				glc_tp( 'ქუთაისის საერთაშორისო აეროპორტი (KUT) არის ის, საიდანაც ბიუჯეტური მოგზაურების უმეტესობა აღწევს საქართველოში — Wizz Air-ისა და დაბალბიუჯეტიანი ფრენების ჰაბი მთელი რეგიონისთვის. სირთულე ისაა, რომ აეროპორტი ქალაქის გარეთ, ცარიელ ადგილას მდებარეობს, ამიტომ ჩამოსვლისთანავე მოცდენილი მანქანა თქვენი მოგზაურობის დაწყების საუკეთესო გზაა. ჩამოსვლისას გხვდებით ტერმინალთან გასაღებით; სრული დაზღვევა, შეუზღუდავი გარბენი, დეპოზიტის გარეშე, გადახდა ჩამოსვლისას.' ) .
				glc_th( 'ქუთაისი — დასავლეთ საქართველოს კარიბჭე' ) .
				glc_tp( 'გამოტოვეთ ტაქსის რიგები და პირდაპირ დასავლეთში გაემართეთ, რომელიც უფრო მწვანე, ველური და ნაკლებად ნანახია, ვიდრე აღმოსავლეთი:' ) .
				glc_tul( [
					'<strong>პრომეთეს მღვიმე და სათაფლია</strong> — უზარმაზარი გამოქვაბულები და დინოზავრის ნაფეხურები, აეროპორტიდან მოკლე მანძილზე.',
					'<strong>ოკაცეს კანიონი და კინჩხის ჩანჩქერი</strong> — კლდის ბილიკები და ორსაფეხურიანი ჩანჩქერი იმერეთში.',
					'<strong>კაცხის სვეტი</strong> — პატარა ეკლესია 40-მეტრიან კირქვის სვეტზე.',
					'<strong>სვანეთი</strong> — მთავარი: მესტია და უშგული, შუა საუკუნეების კოშკებიანი სოფლები მაღალი კავკასიონის ქვეშ. გზა კარგ 4x4-ს დააფასებს.',
				] ) .
				glc_th( 'გვიან ჩამოფრინდებით ქუთაისში?' ) .
				glc_tp( 'დაბალბიუჯეტიანი ფრენები ხშირად უჩვეულო საათებში ჩამოდის. მოგვწერეთ ფრენის ნომერი WhatsApp-ით და მანქანა ტერმინალთან დაგხვდებათ, როცა არ უნდა ჩამოხვიდეთ — დილის ლოდინის გარეშე.' ),
		],
		'ru' => [
			'title' => 'Аренда автомобиля в аэропорту Кутаиси',
			'body'  =>
				glc_tp( 'Аэропорт Кутаиси (KUT) — то, через что в Грузию попадает большинство бюджетных путешественников: хаб Wizz Air и лоукостов для всего региона. Сложность в том, что аэропорт стоит далеко за городом и вокруг почти ничего нет, поэтому машина, ждущая у выхода, — лучший способ начать поездку. Мы встречаем вас у терминала с ключами; полная страховка, без ограничения пробега, без залога, оплата по прилёте.' ) .
				glc_th( 'Кутаиси — ворота Западной Грузии' ) .
				glc_tp( 'Минуйте очереди такси и сразу отправляйтесь на запад — он зеленее, дичее и менее исхожен, чем восток:' ) .
				glc_tul( [
					'<strong>Пещера Прометея и Сатаплиа</strong> — огромные пещеры и следы динозавров недалеко от аэропорта.',
					'<strong>Каньон Окаце и водопад Кинчха</strong> — тропы над обрывом и двухуровневый водопад в Имерети.',
					'<strong>Столп Кацхи</strong> — крошечная церковь на вершине 40-метровой известняковой скалы.',
					'<strong>Сванетия</strong> — главное направление: Местиа и Ушгули, средневековые башенные деревни под высоким Кавказом. Дорога оценит хороший внедорожник.',
				] ) .
				glc_th( 'Прилетаете в Кутаиси поздно?' ) .
				glc_tp( 'Лоукосты часто прибывают в неудобное время. Пришлите нам номер рейса в WhatsApp, и машина будет у терминала, когда бы вы ни приземлились — без ожидания до утра.' ),
		],
	],
	'kobuleti' => [
		'ka' => [
			'title' => 'მანქანის ქირაობა ქობულეთში',
			'body'  =>
				glc_tp( 'ქობულეთი საქართველოს შავი ზღვის სანაპიროს უფრო მშვიდი მონაკვეთია — გრძელი კენჭიანი პლაჟი და მოკრძალებული სასტუმროები, ბათუმიდან ნახევარი საათის სავალზე და ბათუმის აეროპორტთან (BUS) ახლოს. ის შესაფერისია ოჯახებისთვის და მათთვის, ვისაც ზღვა ბათუმის ხალხმრავლობის გარეშე სურს, ხოლო მანქანა მშვიდ პლაჟურ ბაზას მთელი სამხრეთ-დასავლეთის ამოსავალ წერტილად აქცევს. მიწოდება უფასოა თქვენს სასტუმროში ან სასტუმრო სახლში.' ) .
				glc_th( 'ერთდღიანი მოგზაურობები ქობულეთიდან' ) .
				glc_tp( 'აჭარაში ყველაფერი ხელმისაწვდომია, პლუს რამდენიმე რამ პირდაპირ ზღურბლთან:' ) .
				glc_tul( [
					'<strong>ქობულეთის დაცული ტერიტორიები</strong> — ისპანის ტორფიანი ჭაობები და ფრინველები ქალაქის გვერდით.',
					'<strong>კინტრიშის დაცული ტერიტორია</strong> — ტბები და ტყე ახლომდებარე მთებში.',
					'<strong>ბათუმი</strong> — ბულვარი, ბოტანიკური ბაღი და ღამის ცხოვრება, 30 წუთის სავალზე სამხრეთით.',
					'<strong>მტირალა და მაჭახელა</strong> — აჭარის წვიმის ტყე და სასაზღვრო ხეობები, საუკეთესოდ 4x4-ით მისაღწევი.',
				] ) .
				glc_th( 'როგორ მიიღოთ მანქანა ქობულეთში' ) .
				glc_tp( 'ჩამოფრინდით ბათუმში (BUS) ან ქუთაისში (KUT) და მანქანას ქობულეთში მოგიტანთ, ან ორივე აეროპორტში დაგხვდებით. მოგვწერეთ WhatsApp-ით ჩამოსვლის დეტალები ზუსტი ფასისთვის.' ),
		],
		'ru' => [
			'title' => 'Аренда автомобиля в Кобулети',
			'body'  =>
				glc_tp( 'Кобулети — более спокойная часть черноморского побережья Грузии: длинный галечный пляж и тихие гостевые дома, в получасе к северу от Батуми и рядом с аэропортом Батуми (BUS). Подходит семьям и тем, кто хочет моря без батумской толпы, а машина превращает спокойную пляжную базу в стартовую площадку для всего юго-запада. Доставка бесплатна к вашему отелю или гостевому дому.' ) .
				glc_th( 'Однодневные поездки из Кобулети' ) .
				glc_tp( 'Вся Аджария в лёгкой доступности, плюс кое-что прямо под боком:' ) .
				glc_tul( [
					'<strong>Охраняемые территории Кобулети</strong> — торфяники Испани и птицы прямо у города.',
					'<strong>Охраняемая территория Кинтриши</strong> — озёра и лес в горах неподалёку.',
					'<strong>Батуми</strong> — бульвар, ботанический сад и ночная жизнь, в 30 минутах к югу.',
					'<strong>Мтирала и Мачахела</strong> — аджарский дождевой лес и приграничные долины, лучше добираться на внедорожнике.',
				] ) .
				glc_th( 'Как получить машину в Кобулети' ) .
				glc_tp( 'Прилетайте в Батуми (BUS) или Кутаиси (KUT), и мы привезём машину в Кобулети или встретим вас в любом из аэропортов. Напишите детали прибытия в WhatsApp для точной цены.' ),
		],
	],
];

foreach ( $trans as $slug => $locales ) {
	$found = get_posts( [ 'post_type' => 'city', 'name' => $slug, 'posts_per_page' => 1, 'post_status' => 'any' ] );
	if ( ! $found ) {
		continue;
	}
	$pid = $found[0]->ID;
	foreach ( $locales as $loc => $tb ) {
		update_post_meta( $pid, "glc_title_{$loc}", $tb['title'] );
		update_post_meta( $pid, "glc_body_{$loc}", $tb['body'] );
	}
	WP_CLI::log( "  ✓ ka/ru translations → /car-rental-{$slug}/" );
}

WP_CLI::log( 'Done. Now run:  wp rewrite flush' );
