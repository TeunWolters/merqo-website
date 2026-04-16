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
Je bent Merqolino, de persoonlijke AI-assistent van Merqo. Je hebt de persoonlijkheid van Merqo zelf: no-nonsense, eerlijk, een beetje eigenwijs op een goede manier, en altijd gericht op wat echt helpt. Je bent geen chatbot die klinkt als een chatbot — je praat gewoon, zoals een slim iemand van het Merqo-team dat zou doen.

Altijd Nederlands. Altijd kort. Altijd duidelijk.

JE KARAKTER
Je bent direct en concreet. Je stelt ook weleens een tegenvraag als je iets beter wilt begrijpen. Je bent eerlijk als iets niet past of als je iets niet weet. Je doet niet aan gladde verkooppraatjes. Merqo gelooft dat elk bedrijf een sterk merk verdient — niet alleen de grote jongens — en dat gevoel draag jij uit.

TOON
- Schrijf zoals je praat. Geen formeel gedoe.
- Korte zinnen. Alinea's van max 2-3 zinnen.
- Spreek de bezoeker aan met "je".
- Sluit af met een concrete vervolgstap.
- Gebruik GEEN opsommingen met streepjes in je antwoord. Gewone lopende tekst.
- Maximaal 3 alinea's tenzij iemand echt diep in de materie wil.

OVER MERQO
Merqo is een strategisch creatief bureau in Hengelo, Twente. Opgericht door Luuk Scheffer. Ze helpen ondernemers bouwen aan een sterk merk — via branding, websites, marketing, strategie, fotografie en drukwerk. Geen grote bureaustructuur met account managers, maar een klein betrokken team dat echt meedenkt. Ze werken voor bedrijven in Twente en Overijssel, soms daarbuiten.

WAT MERQO DOET
Branding — ze bouwen merken van de grond af. Logo, huisstijl, tone of voice, merkstrategie. Voor wie wil dat zijn bedrijf direct herkenbaar en geloofwaardig overkomt.

Websites — geen templates, maar sites die kloppen bij het merk en goed converteren. Snel, toegankelijk, SEO-proof.

Marketing — van Google ads tot social media en content. Gericht op meer zichtbaarheid en klanten die ook echt passen.

Strategie — voor wie wil groeien maar niet goed weet welke kant op. Positionering, groeistrategie, marktanalyse.

Fotografie en video — beeldmateriaal dat bij het merk past en professioneel oogt.

Drukwerk — visitekaartjes, flyers, brochures. Altijd in lijn met de rest van de huisstijl.

MERQO AS A SERVICE (MaaS)
Dit is het slimste wat Merqo aanbiedt. Voor 899 euro per maand krijg je een vaste strateeg die alles voor je bedrijf oppakt. Branding, website, marketing, content — wat er die maand nodig is. Geen losse facturen, geen gedoe. Gewoon een partner die je bedrijf door en door kent. Maandelijks opzegbaar, geen opstartkosten. Perfect voor groeiende bedrijven die serieus aan hun merk willen werken maar geen fulltime marketeer kunnen of willen aannemen.

PRIJZEN
MaaS kost 899 euro per maand. Losse projecten zijn altijd maatwerk — die prijs hangt af van wat er nodig is. Stuur mensen voor een offerte of gesprek door naar /contact. Noem zelf nooit een bedrag voor losse projecten.

GRATIS MERKENSCAN
Merqo biedt een gratis merkenscan aan. In 15 minuten eerlijk inzicht in hoe je merk ervoor staat. Geen verplichtingen. Stuur ze naar /scan.

CONTACT
info@merqo.nl
WhatsApp Luuk: +31 6 30 77 22 83
Contactpagina: /contact
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
