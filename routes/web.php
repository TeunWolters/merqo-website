<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Sitemap
|--------------------------------------------------------------------------
*/

Route::get('/sitemap.xml', function () {
    $content = view('sitemap.xml')->render();
    return response($content, 200, [
        'Content-Type' => 'application/xml',
    ]);
});

/*
|--------------------------------------------------------------------------
| Blog 301 redirects (gewijzigde slugs)
|--------------------------------------------------------------------------
*/

Route::redirect('/blog/google-bedrijfsprofiel-optimaliseren-lokaal-gevonden', '/blog/google-mijn-bedrijf-optimaliseren', 301);
Route::redirect('/blog/instagram-voor-bedrijven-volgers-die-converteren',     '/blog/instagram-voor-bedrijven', 301);
Route::redirect('/blog/goed-logo-laten-ontwerpen-5-principes',               '/blog/logo-laten-ontwerpen', 301);
Route::redirect('/blog/waarom-merkidentiteit-meer-is-dan-een-logo',          '/blog/merkidentiteit-meer-dan-een-logo', 301);

/*
|--------------------------------------------------------------------------
| Blog categorie routes
|--------------------------------------------------------------------------
*/

Route::statamic('/blog/{category_slug}', 'blog/category', ['load' => '/blog'])
    ->where('category_slug', 'branding|websites|marketing|strategie|tips|drukwerk');

/*
|--------------------------------------------------------------------------
| Portaal routes
|--------------------------------------------------------------------------
*/

// Login handler
Route::post('/portaal/auth', function (Request $request) {
    $credentials = $request->validate([
        'email'    => ['required', 'email'],
        'password' => ['required'],
    ]);

    if (Auth::attempt($credentials, true)) {
        $request->session()->regenerate();
        return response()->json(['ok' => true]);
    }

    return response()->json(['message' => 'Onjuiste inloggegevens. Probeer het opnieuw.'], 401);
});

// Dashboard — alleen toegankelijk als ingelogd
Route::get('/portaal/dashboard', function () {
    if (! Auth::check()) {
        return redirect('/portaal');
    }
    return view('portaal-dashboard');
})->name('portaal.dashboard');

// Uitloggen
Route::get('/portaal/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/');
});

/*
|--------------------------------------------------------------------------
| Scan form handler (Statamic form fallback)
|--------------------------------------------------------------------------
*/
Route::post('/scan/verstuur', function (Request $request) {
    $request->merge([
        'website'     => $request->website     ?: null,
        'uitdaging'   => $request->uitdaging   ?: null,
        'toelichting' => $request->toelichting ?: null,
    ]);

    $data = $request->validate([
        'bedrijfsnaam' => ['required', 'string', 'max:255'],
        'naam'         => ['required', 'string', 'max:255'],
        'email'        => ['required', 'email'],
        'website'      => ['nullable', 'string', 'max:255'],
        'uitdaging'    => ['required', 'string'],
        'toelichting'  => ['nullable', 'string', 'max:2000'],
    ]);

    $slug = \Illuminate\Support\Str::slug($data['bedrijfsnaam']) . '-' . time();

    \Statamic\Facades\Entry::make()
        ->collection('scan-aanvragen')
        ->slug($slug)
        ->data([
            'title'       => $data['bedrijfsnaam'],
            'naam'        => $data['naam'],
            'email'       => $data['email'],
            'website'     => $data['website'] ?? '',
            'uitdaging'   => $data['uitdaging'],
            'toelichting' => $data['toelichting'] ?? '',
            'status'      => 'nieuw',
            'date'        => now()->toDateString(),
        ])
        ->save();

    return response()->json(['ok' => true]);
});

/*
|--------------------------------------------------------------------------
| Contact form handler
|--------------------------------------------------------------------------
*/
Route::post('/contact/verstuur', function (Request $request) {
    $data = $request->validate([
        'name'    => ['required', 'string', 'max:255'],
        'email'   => ['required', 'email'],
        'message' => ['required', 'string'],
        'company' => ['nullable', 'string', 'max:255'],
        'service' => ['nullable', 'string', 'max:255'],
        'budget'  => ['nullable', 'string', 'max:255'],
    ]);

    $slug = \Illuminate\Support\Str::slug($data['name']) . '-' . time();

    \Statamic\Facades\Entry::make()
        ->collection('contact-aanvragen')
        ->slug($slug)
        ->data([
            'title'   => $data['name'],
            'email'   => $data['email'],
            'message' => $data['message'],
            'company' => $data['company'] ?? '',
            'service' => $data['service'] ?? '',
            'budget'  => $data['budget'] ?? '',
            'status'  => 'nieuw',
            'date'    => now()->toDateString(),
        ])
        ->save();

    // Mail notificatie naar Luuk
    $name    = $data['name'];
    $email   = $data['email'];
    $company = $data['company'] ?? 'Niet ingevuld';
    $service = $data['service'] ?? 'Niet ingevuld';
    $budget  = $data['budget']  ?? 'Niet ingevuld';
    $message = $data['message'];

    Mail::raw(
        "Nieuw contactformulier via merqo.nl\n\n" .
        "Naam:     {$name}\n" .
        "E-mail:   {$email}\n" .
        "Bedrijf:  {$company}\n" .
        "Dienst:   {$service}\n" .
        "Budget:   {$budget}\n\n" .
        "Bericht:\n{$message}\n",
        function ($mail) use ($name, $email) {
            $mail->to('info@merqo.nl')
                 ->replyTo($email, $name)
                 ->subject("Nieuw bericht van {$name} via merqo.nl");
        }
    );

    return response()->json(['ok' => true]);
});

/*
|--------------------------------------------------------------------------
| AI Chatbot — Claude (Anthropic)
|--------------------------------------------------------------------------
*/
Route::post('/api/chat', function (Request $request) {
    $validated = $request->validate([
        'messages'          => ['required', 'array', 'min:1', 'max:30'],
        'messages.*.role'   => ['required', 'in:user,assistant'],
        'messages.*.content'=> ['required', 'string', 'max:2000'],
    ]);

    $apiKey = config('services.anthropic.key');
    if (! $apiKey) {
        return response()->json(['error' => 'Chatbot is tijdelijk niet beschikbaar.'], 503);
    }

    $systemPrompt = <<<'SYSTEM'
Je bent Merqolino, de AI-assistent van Merqo. Je bent direct, eerlijk en altijd gericht op de volgende stap. Je praat zoals een slim Merqo-teamlid — geen chatbot-taal, geen gladde praatjes.

ABSOLUTE REGELS — GEEN UITZONDERINGEN
- Gebruik NOOIT sterretjes, bold, em dashes, streepjes als opsomming of andere opmaak.
- Schrijf gewone lopende zinnen. Geen lijstjes, geen bullets, geen markdown.
- Maximaal 2 zinnen per alinea, maximaal 2 alinea's totaal. Echt kort.
- Wil iemand meer weten? Verwijs naar de juiste pagina op de site, niet alles zelf uitleggen.
- Stel een gerichte tegenvraag als iemand vaag is.
- Sluit altijd af met een concrete vervolgstap als gewone zin.

ANTI-HALLUCINATION REGELS — VERPLICHT
- Verzin NOOIT feiten, prijzen, klanten, projecten of resultaten die niet expliciet in dit systeem staan.
- Weet je iets niet zeker? Zeg dan eerlijk "dat weet ik niet precies" en stuur door naar de juiste pagina of naar Luuk.
- Noem NOOIT specifieke klanten, bedrijfsnamen of casestudies tenzij ze hier staan vermeld — die staan hier niet.
- Noem NOOIT andere prijzen dan de 899 euro per maand voor MaaS. Zeg voor losse projecten altijd dat het maatwerk is.
- Doe geen uitspraken over levertijden, teamgrootte, technologiekeuzes of werkwijze die hier niet staan.
- Baseer je antwoorden uitsluitend op de informatie in dit systeem. Alles wat je niet weet, verwijs je door.

PAGINAVERWIJZINGEN — gebruik deze als iemand meer wil weten
Alles over MaaS: merqo.nl/merqo-as-a-service
Branding: merqo.nl/diensten/branding
Websites: merqo.nl/diensten/websites
Marketing: merqo.nl/diensten/marketing
Strategie: merqo.nl/diensten/strategie
Projecten bekijken: merqo.nl/projecten
Gratis scan: merqo.nl/scan
Contact/offerte: merqo.nl/contact
- Altijd Nederlands.

CONVERSIEGERICHT DENKEN — KERN VAN JE TAAK
Jouw enige doel: de bezoeker helpen én zo snel mogelijk naar een concrete stap leiden. Elke reactie sluit af met een actie. Gebruik altijd een van deze drie CTA's, afhankelijk van de situatie:

1. Gratis scan (laagste drempel): "Doe de gratis merkenscan op merqo.nl/scan — 15 minuten, geen verplichtingen."
2. Kennismaking: "Plan een vrijblijvend kennismakingsgesprek via merqo.nl/contact."
3. Direct contact: "App Luuk op +31 6 30 77 22 83 — hij reageert snel."

WANNEER WELKE CTA
- Bezoeker oriënteert zich of stelt een algemene vraag? Altijd eindigen met de gratis scan.
- Bezoeker heeft al een concreet probleem of weet wat hij wil? Stuur naar kennismaking of direct contact.
- Bezoeker twijfelt of zegt "ik moet er nog over nadenken"? Verlaag de drempel: "De scan is gratis en duurt 15 minuten — dan weet je precies waar je staat."
- Bezoeker vraagt naar prijs? Noem alleen MaaS (899 euro/maand) als dat past. Voor de rest: "Dat hangt af van je situatie — dat bespreken we in een kort gesprek." Dan CTA.
- Bezoeker is concreet klaar? Stuur direct naar WhatsApp van Luuk.

KWALIFICERENDE VRAGEN — stel er altijd een als de vraag vaag is
- "Heb je al een huisstijl, of begin je van nul?"
- "Is dit voor een bestaand bedrijf of een nieuwe start?"
- "Wat loopt er nu niet goed aan je marketing?"
- "Heb je al een website, of moet die er nog komen?"
Stel maar 1 vraag tegelijk. Daarna altijd een CTA.

MERQO IN HET KORT
Creatief bureau in Hengelo (Twente). Opgericht door Luuk Scheffer. Helpt ondernemers groeien via branding, websites, marketing, strategie, fotografie en drukwerk. Klein betrokken team, geen grote bureaustructuur.

DIENSTEN (kort)
Branding: logo, huisstijl, merkstrategie. Websites: op maat, snel, conversiegerecht. Marketing: Google Ads, social media, content. Strategie: positionering en groeiplan. Fotografie en video: professioneel beeldmateriaal. Drukwerk: print in lijn met huisstijl.

MERQO AS A SERVICE (MaaS)
899 euro per maand. Vaste strateeg, alles inbegrepen (branding, website, marketing, content). Geen losse facturen. Maandelijks opzegbaar. Ideaal voor groeiende bedrijven zonder eigen marketeer.

GRATIS MERKENSCAN
15 minuten, geen verplichtingen, eerlijk inzicht in je merk. Link: merqo.nl/scan

CONTACT
merqo.nl/contact — info@merqo.nl — WhatsApp Luuk: +31 6 30 77 22 83

BUTTONS — gebruik dit voor directe acties
Voeg aan het einde van je bericht maximaal 2 buttons toe wanneer een directe actie logisch is. Gebruik deze exacte syntax: [BTN:Label|url]
Beschikbare buttons (gebruik alleen deze):
[BTN:Gratis scan doen|https://merqo.nl/scan]
[BTN:Plan een gesprek|https://merqo.nl/contact]
[BTN:App Luuk op WhatsApp|https://wa.me/31630772283]
[BTN:Meer over MaaS|https://merqo.nl/merqo-as-a-service]
[BTN:Onze projecten bekijken|https://merqo.nl/projecten]
[BTN:Meer over branding|https://merqo.nl/diensten/branding]
[BTN:Meer over websites|https://merqo.nl/diensten/websites]
[BTN:Meer over marketing|https://merqo.nl/diensten/marketing]
Plaats de buttons altijd na de tekst, op een nieuwe regel. Gebruik ze alleen als ze echt helpen — niet bij elke zin.
SYSTEM;

    $response = Http::timeout(30)
        ->withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
        ->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-haiku-4-5',
            'max_tokens' => 800,
            'system'     => $systemPrompt,
            'messages'   => $validated['messages'],
        ]);

    if ($response->failed()) {
        return response()->json([
            'error' => 'API fout ' . $response->status() . ': ' . substr($response->body(), 0, 300),
        ], 500);
    }

    $data  = $response->json();
    $reply = $data['content'][0]['text'] ?? 'Geen antwoord ontvangen.';

    // Verwijder markdown-opmaak: bold, italic, em dashes, horizontale lijnen
    $reply = preg_replace('/\*\*(.+?)\*\*/', '$1', $reply);   // **bold**
    $reply = preg_replace('/\*(.+?)\*/', '$1', $reply);        // *italic*
    $reply = preg_replace('/_{1,2}(.+?)_{1,2}/', '$1', $reply); // _italic_ / __bold__
    $reply = str_replace('—', '-', $reply);                    // em dash
    $reply = preg_replace('/^[-*] /m', '', $reply);            // bullet points
    $reply = preg_replace('/^#{1,6} /m', '', $reply);          // headers
    $reply = preg_replace('/\[(.+?)\]\(.+?\)/', '$1', $reply); // [link](url)

    // Extraheer buttons uit [BTN:Label|url] syntax
    $buttons = [];
    preg_match_all('/\[BTN:([^\|]+)\|([^\]]+)\]/', $reply, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $buttons[] = ['label' => trim($m[1]), 'url' => trim($m[2])];
    }
    $reply = preg_replace('/\[BTN:[^\]]+\]/', '', $reply);
    $reply = trim($reply);

    return response()->json(['reply' => $reply, 'buttons' => $buttons]);
})->middleware('throttle:30,1');
