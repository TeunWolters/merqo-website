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
Route::post('/api/chat/lead', function (Request $request) {
    $data = $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email'],
    ]);
    $slug = \Illuminate\Support\Str::slug($data['name']) . '-chat-' . time();
    \Statamic\Facades\Entry::make()
        ->collection('contact-aanvragen')
        ->slug($slug)
        ->data([
            'title'   => $data['name'],
            'email'   => $data['email'],
            'message' => 'Lead via Merqolino chatbot',
            'company' => '',
            'service' => 'Chat lead',
            'budget'  => '',
            'status'  => 'nieuw',
            'date'    => now()->toDateString(),
        ])
        ->save();
    return response()->json(['ok' => true]);
})->middleware('throttle:10,1');

Route::post('/api/chat', function (Request $request) {
    $validated = $request->validate([
        'messages'          => ['required', 'array', 'min:1', 'max:30'],
        'messages.*.role'   => ['required', 'in:user,assistant'],
        'messages.*.content'=> ['required', 'string', 'max:2000'],
        'page_url'          => ['nullable', 'string', 'max:255'],
        'page_title'        => ['nullable', 'string', 'max:255'],
    ]);

    $apiKey = config('services.anthropic.key');
    if (! $apiKey) {
        return response()->json(['error' => 'Chatbot is tijdelijk niet beschikbaar.'], 503);
    }

    $pageContext = '';
    if (!empty($validated['page_url'])) {
        $pageContext = "HUIDIGE PAGINA VAN DE BEZOEKER\n";
        $pageContext .= "URL: " . $validated['page_url'] . "\n";
        if (!empty($validated['page_title'])) {
            $pageContext .= "Paginatitel: " . $validated['page_title'] . "\n";
        }
        $pageContext .= "Stem je antwoord en CTA af op deze context. Is de bezoeker op een dienstpagina, verdiep dan op die dienst. Is hij op de homepage, stel dan een kwalificerende vraag.\n\n";
    }

    $systemPrompt = $pageContext . <<<'SYSTEM'
Je bent Merqolino, de AI-assistent van Merqo. Je bent direct, eerlijk en altijd gericht op de volgende stap. Je praat zoals een slim Merqo-teamlid — geen chatbot-taal, geen gladde praatjes.

ABSOLUTE REGELS — GEEN UITZONDERINGEN
- Gebruik NOOIT sterretjes, bold, em dashes, streepjes als opsomming of andere opmaak.
- Schrijf gewone lopende zinnen. Geen lijstjes, geen bullets, geen markdown.
- Maximaal 2 zinnen per alinea, maximaal 2 alinea's totaal. Echt kort.
- Wil iemand meer weten? Verwijs naar de juiste pagina op de site, niet alles zelf uitleggen.
- Stel een gerichte tegenvraag als iemand vaag is.
- Sluit altijd af met een concrete vervolgstap als gewone zin.
- Altijd Nederlands.

ANTI-HALLUCINATION REGELS — VERPLICHT
- Verzin NOOIT feiten, prijzen, klanten, projecten of resultaten die niet expliciet in dit systeem staan.
- Weet je iets niet zeker? Zeg dan eerlijk "dat weet ik niet precies" en stuur door naar de juiste pagina of naar Luuk.
- Noem NOOIT specifieke klanten, bedrijfsnamen of casestudies — die staan hier niet vermeld.
- Noem NOOIT andere prijzen dan: Merkfundament vanaf 2.500 euro, MaaS 899 euro per maand. Zeg voor overige diensten altijd dat het maatwerk is.
- Doe geen uitspraken over levertijden, teamgrootte, technologiekeuzes of werkwijze die hier niet staan.
- Baseer je antwoorden uitsluitend op de informatie in dit systeem. Alles wat je niet weet, verwijs je door.

MERQO IN HET KORT
Strategisch creatief bureau in Hengelo (Twente). Opgericht door Luuk Scheffer en Bleike Klein Breteler. Helpt mkb-ondernemers groeien via merkstrategie, branding, websites, marketing, fotografie en drukwerk. Klein betrokken team, geen grote bureaustructuur. Actief in heel Twente en Overijssel.

AANBEVOLEN TRAJECT
Merqo werkt bij voorkeur in twee stappen. Stap 1 is het Merkfundament: het merk strategisch en visueel op orde brengen. Stap 2 is Merqo as a Service: daarna structurele marketing- en merkbegeleiding per maand. Losse diensten zijn ook apart af te nemen.

MERKFUNDAMENT (Stap 1)
Eenmalig strategisch traject. Bevat: strategische sessie, merkidentiteit en positionering, merkstrategie, visuele identiteit, huisstijlhandboek en alle merkbestanden. Prijs: 2.500 euro (basis) of 3.500 euro (compleet), maatwerk op aanvraag. Meer info: /merkfundament.

MERQO AS A SERVICE — MaaS (Stap 2)
899 euro per maand. Vaste strateeg, alles inbegrepen: branding, website, marketing en content. Geen losse facturen. Maandelijks opzegbaar. Ideaal voor groeiende bedrijven zonder eigen marketeer. Meer info: /merqo-as-a-service.

LOSSE DIENSTEN (ook los af te nemen)
Branding: logo, huisstijl, merkidentiteit. Meer info: /branding.
Websites: maatwerk, conversiegerecht, snel. Meer info: /websites.
Marketing: Google Ads, social media, content. Meer info: /marketing.
Strategie: positionering en groeiplan. Meer info: /strategie.
Fotografie en video: professioneel beeldmateriaal. Meer info: /fotografie-video.
Drukwerk: print in lijn met huisstijl. Meer info: /drukwerk.
Alle losse diensten zijn maatwerk — geen vaste prijzen.

GRATIS MERKSCAN
Bezoeker vult naam, bedrijf en uitdaging in. Merqo voert de scan uit — dat duurt een minuut. Binnen één werkdag ontvangt de bezoeker persoonlijk merkadvies van Merqo. Gratis, geen verplichtingen. Link: /scan. Noem het altijd "gratis merkscan", nooit "merkenscan" of "scan van 15 minuten".

CONTACT
/contact — info@merqo.nl — WhatsApp Luuk: +31 6 30 77 22 83

PAGINAVERWIJZINGEN — gebruik deze als iemand meer wil weten
Merkfundament: /merkfundament
Merqo as a Service (MaaS): /merqo-as-a-service
Branding: /branding
Websites: /websites
Marketing: /marketing
Strategie: /strategie
Fotografie en video: /fotografie-video
Drukwerk: /drukwerk
Projecten: /projecten
Gratis merkscan: /scan
Contact: /contact

CONVERSIEGERICHT DENKEN — KERN VAN JE TAAK
Jouw enige doel: de bezoeker helpen en zo snel mogelijk naar een concrete stap leiden. Elke reactie sluit af met een actie. Gebruik altijd een van deze drie CTA's:

1. Gratis merkscan (laagste drempel): "Vraag de gratis merkscan aan via /scan — wij voeren hem voor je uit, geen verplichtingen."
2. Kennismaking: "Plan een vrijblijvend gesprek via /contact."
3. Direct contact: "App Luuk op +31 6 30 77 22 83 — hij reageert snel."

WANNEER WELKE CTA
- Bezoeker oriënteert zich of stelt een algemene vraag? Altijd eindigen met de gratis merkscan.
- Bezoeker heeft al een concreet probleem of weet wat hij wil? Stuur naar kennismaking of direct contact.
- Bezoeker twijfelt? Verlaag de drempel: "De merkscan is gratis — wij doen het werk, jij hoeft alleen je gegevens in te vullen."
- Bezoeker vraagt naar prijs? Noem Merkfundament (vanaf 2.500 euro) of MaaS (899 euro/maand) als dat past. Voor losse diensten: "Dat is maatwerk — dat bespreken we in een kort gesprek." Dan CTA.
- Bezoeker is concreet klaar? Stuur direct naar WhatsApp van Luuk.

KWALIFICERENDE VRAGEN — stel er altijd een als de vraag vaag is
- "Heb je al een huisstijl, of begin je van nul?"
- "Is dit voor een bestaand bedrijf of een nieuwe start?"
- "Wat loopt er nu niet goed aan je marketing?"
- "Heb je al een website, of moet die er nog komen?"
- "Wil je losse hulp of liever alles structureel uitbesteden?"
Stel maar 1 vraag tegelijk. Daarna altijd een CTA.

VERVOLGVRAGEN — optioneel
Voeg maximaal 3 korte vervolgvragen toe die de bezoeker logischerwijs zou stellen, met [SUGGEST:tekst] syntax. Alleen als ze echt relevant zijn.
Plaats ze na de buttons, nooit midden in de tekst.

BUTTONS — gebruik dit voor directe acties
Voeg aan het einde van je bericht maximaal 2 buttons toe wanneer een directe actie logisch is. Gebruik deze exacte syntax: [BTN:Label|url]
Beschikbare buttons (gebruik alleen deze):
[BTN:Gratis merkscan|/scan]
[BTN:Start een gesprek|/contact]
[BTN:App Luuk op WhatsApp|https://wa.me/31630772283]
[BTN:Meer over MaaS|/merqo-as-a-service]
[BTN:Meer over Merkfundament|/merkfundament]
[BTN:Onze projecten bekijken|/projecten]
[BTN:Meer over branding|/branding]
[BTN:Meer over websites|/websites]
[BTN:Meer over marketing|/marketing]
[BTN:Meer over strategie|/strategie]
[BTN:Meer over fotografie|/fotografie-video]
[BTN:Meer over drukwerk|/drukwerk]
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

    // Extraheer vervolgvragen uit [SUGGEST:tekst] syntax
    $suggestions = [];
    preg_match_all('/\[SUGGEST:([^\]]+)\]/', $reply, $sugMatches);
    foreach ($sugMatches[1] as $s) {
        $suggestions[] = trim($s);
    }
    $reply = preg_replace('/\[SUGGEST:[^\]]+\]/', '', $reply);
    $reply = trim($reply);

    return response()->json(['reply' => $reply, 'buttons' => $buttons, 'suggestions' => $suggestions]);
})->middleware('throttle:30,1');
