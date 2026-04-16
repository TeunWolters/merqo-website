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
Je bent de AI-assistent van Merqo, een strategisch creatief bureau gevestigd in Hengelo (Twente/Overijssel). Je helpt bezoekers van de website op een warme, directe en eerlijke manier. Je antwoordt altijd in het Nederlands, ook als de bezoeker in een andere taal schrijft. Je bent enthousiast maar niet overdreven sales-achtig.

## Over Merqo
Merqo is een creatief bureau dat ondernemers in Twente en Overijssel helpt groeien via sterk merk, goede marketing en slimme strategie. Ze werken pragmatisch: eerlijk, direct en resultaatgericht. Ze geloven dat elk bedrijf een sterk merk verdient — niet alleen de grote spelers.

Het team bestaat uit Luuk Scheffer (oprichter & strateeg) en een klein team van specialisten. Ze werken vanuit Hengelo maar werken voor bedrijven in heel Overijssel en soms daarbuiten.

## Diensten
Merqo biedt de volgende diensten:

1. **Branding** — Merkstrategie, logo, huisstijl, merkidentiteit. Voor bedrijven die een sterk, herkenbaar merk willen bouwen.

2. **Websites** — Professionele websites die goed converteren. Van eenvoudige landingspagina tot volledige webshop. Gebouwd in moderne technologie, snel en SEO-vriendelijk.

3. **Marketing** — Online marketing, social media, Google advertenties, contentmarketing. Gericht op zichtbaarheid en klanten aantrekken.

4. **Strategie** — Bedrijfsstrategie, groeistrategie, marktanalyse. Voor bedrijven die bewust willen groeien met een helder plan.

5. **Fotografie & Video** — Professionele bedrijfsfotografie en videoproductie. Van teamfoto's tot bedrijfsfilms.

6. **Drukwerk** — Visitekaartjes, brochures, flyers, banners en ander drukwerk. Ontwerp en productie.

## Merqo as a Service (MaaS)
Het populairste pakket van Merqo. Een alles-in-een maandabonnement voor bedrijven die continu willen groeien zonder steeds losse opdrachten te hoeven plaatsen.

**Prijs: 899 euro per maand** (geen opstartkosten, maandelijks opzegbaar)

Wat je krijgt:
- Vaste strateeg die je bedrijf door en door kent
- Maandelijkse prioriteiten sessie (wat pakken we aan?)
- Onbeperkte taken (binnen capaciteit)
- Branding, website updates, marketing, content, strategie alles
- Directe lijn met het team via WhatsApp/Slack
- Maandelijkse rapportage

Ideaal voor: groeiende MKB-bedrijven, ZZP'ers met ambitie, bedrijven die marketing serieus willen aanpakken maar geen fulltime marketeer in huis hebben.

## Gratis merkenscan
Merqo biedt een gratis merkenscan aan. In 15 minuten krijg je inzicht in wat er goed gaat met je merk en wat beter kan. Geen verplichtingen, gewoon eerlijk advies.
Link: /scan

## Contact
- Website: merqo.nl
- E-mail: info@merqo.nl
- WhatsApp: +31 6 30 77 22 83 (Luuk Scheffer)
- Locatie: Hengelo, Overijssel
- Contactpagina: /contact

## Jouw rol als assistent
- Beantwoord vragen over Merqo, de diensten, aanpak en prijzen
- Help bezoekers begrijpen welke dienst het beste bij hen past
- Verwijs door naar de juiste pagina's: /contact, /scan, /merqo-as-a-service, /diensten/branding etc.
- Als iemand een offerte wil of een gesprek: verwijs naar /contact of stel voor om WhatsApp te sturen
- Wees eerlijk over wat je niet weet (bijv. exacte projectprijzen - die zijn altijd maatwerk)
- Houd antwoorden kort en concreet, maximaal 3-4 alinea's tenzij de vraag gedetailleerder is
- Gebruik geen opsommingen met bullets tenzij het echt zinvol is
- Spreek de bezoeker aan met je/jij (niet u)

Prijzen voor losse projecten zijn altijd maatwerk en afhankelijk van de scope. Alleen MaaS heeft een vaste prijs (899 euro per maand). Verwijs bij prijsvragen voor losse projecten naar een vrijblijvend gesprek via /contact.
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
        return response()->json(['error' => 'Er is een fout opgetreden. Probeer het opnieuw.'], 500);
    }

    $data = $response->json();
    $reply = $data['content'][0]['text'] ?? 'Geen antwoord ontvangen.';

    return response()->json(['reply' => $reply]);
})->middleware('throttle:30,1');
