<?php

set_time_limit(130);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Client\ConnectionException;
use App\Http\Controllers\AuthController;
use App\Models\Pdf;
use App\Models\Question;


/*
|--------------------------------------------------------------------------
| YARDIMCI: Windows yolunu WSL yoluna çevir
|--------------------------------------------------------------------------
*/

function windowsToWsl(string $path): string
{
    $path = str_replace('\\', '/', $path);

    if (preg_match('/^([A-Za-z]):(.*)$/', $path, $matches)) {
        return '/mnt/' . strtolower($matches[1]) . $matches[2];
    }

    return $path;
}


/*
|--------------------------------------------------------------------------
| AUTH ROUTE'LARI
|--------------------------------------------------------------------------
| Giriş gerektirmez.
|--------------------------------------------------------------------------
*/

Route::get('/register', [AuthController::class, 'showRegister'])
    ->name('register');

Route::post('/register', [AuthController::class, 'register']);

Route::get('/login', [AuthController::class, 'showLogin'])
    ->name('login');

Route::post('/login', [AuthController::class, 'login']);

Route::post('/logout', [AuthController::class, 'logout'])
    ->name('logout');


/*
|--------------------------------------------------------------------------
| KORUNAN ROUTE'LAR
|--------------------------------------------------------------------------
| Bu grubun içindeki bütün sayfalara giriş yapılmış olması gerekir.
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {


    /*
    |--------------------------------------------------------------------------
    | ANA SAYFA
    |--------------------------------------------------------------------------
    */

    Route::get('/', function () {

        return view('pdf');
    });


    /*
    |--------------------------------------------------------------------------
    | PDF ÖNİZLEME
    |--------------------------------------------------------------------------
    | PDF doğrudan public URL'den erişilemez.
    | Önce kullanıcının gerçekten bu PDF'nin sahibi olduğu kontrol edilir.
    |--------------------------------------------------------------------------
    */

    Route::get('/pdf/{id}', function (int $id) {

        $pdfKayit = Pdf::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$pdfKayit) {
            abort(404);
        }

        $tamYol = storage_path(
            'app/private/' . $pdfKayit->yol
        );

        if (!file_exists($tamYol)) {
            abort(404);
        }

        return response()->file($tamYol, [

            'Content-Type' =>
            'application/pdf',

            'Content-Disposition' =>
            'inline; filename="' .
                $pdfKayit->dosya_adi .
                '"',

        ]);
    })->name('pdf.view');


    /*
    |--------------------------------------------------------------------------
    | TEK PDF İŞLE
    |--------------------------------------------------------------------------
    | PDF:
    |
    | 1. Storage'a kaydedilir
    | 2. Smalot ile metin çıkarılır
    | 3. Metin yoksa OCR çalışır
    | 4. UTF-8 temizliği yapılır
    | 5. Chunk'lara ayrılır
    | 6. PDF DB'ye kaydedilir
    | 7. Ollama embedding oluşturur
    | 8. Weaviate'a chunk + embedding kaydedilir
    |--------------------------------------------------------------------------
    */

    function pdfDosyasiniIsle($dosya): array
    {
        $orijinalAd = $dosya->getClientOriginalName();


        /*
        | PDF'yi private storage'a kaydet
        */

        $yol = $dosya->store('pdfler');

        $pdfPath = storage_path(
            'app/private/' . $yol
        );


        /*
        |--------------------------------------------------------------------------
        | 1. METNİ ÇIKAR (pdftotext -layout, TABLOLARI KORUYAN yöntem)
        |--------------------------------------------------------------------------
        | Smalot'un aksine -layout, tablodaki sütun hizasını (boşluklarla)
        | koruyarak metne çevirir; bu yüzden isim/görev gibi hücre eşleşmeleri
        | karışmaz. pdftotext, pdftoppm ile aynı poppler-utils paketinden gelir.
        |--------------------------------------------------------------------------
        */

        $sayfaMetinleri = [];

        $wslPdfPath =
            windowsToWsl($pdfPath);

        $pdftotextCikti =
            shell_exec(
                'wsl pdftotext -layout -enc UTF-8 ' .
                    escapeshellarg($wslPdfPath) .
                    ' -'
            );


        if (
            $pdftotextCikti &&
            trim($pdftotextCikti) !== ''
        ) {

            /*
            | pdftotext sayfalar arasına form-feed (\x0C) karakteri koyar.
            */

            $sayfaParcalari =
                explode(
                    "\x0C",
                    $pdftotextCikti
                );


            if (
                !empty($sayfaParcalari) &&
                trim(end($sayfaParcalari)) === ''
            ) {

                array_pop($sayfaParcalari);
            }


            foreach (
                $sayfaParcalari
                as $sayfaIndex => $sayfaMetni
            ) {

                $temizMetin =
                    trim($sayfaMetni);


                if ($temizMetin !== '') {

                    $sayfaMetinleri[$sayfaIndex + 1] =
                        $temizMetin;
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | 1B. YEDEK: SMALOT
        |--------------------------------------------------------------------------
        | pdftotext başarısız olursa (WSL/poppler erişilemedi, şifreli PDF vb.)
        | Smalot ile dener. Düz metinli PDF'lerde işe yarar, tablo hizası
        | garanti değildir ama hiç metin alamamaktan iyidir.
        |--------------------------------------------------------------------------
        */

        if (empty($sayfaMetinleri)) {

            try {

                $parser = new \Smalot\PdfParser\Parser();

                $pdf = $parser->parseFile($pdfPath);


                foreach ($pdf->getPages() as $sayfaIndex => $sayfaObj) {

                    $temizMetin =
                        trim($sayfaObj->getText());


                    if ($temizMetin !== '') {

                        $sayfaMetinleri[$sayfaIndex + 1] =
                            $temizMetin;
                    }
                }
            } catch (\Throwable $e) {

                Log::warning(
                    'PDF ayrıştırma hatası (Smalot yedek), OCR denenecek',
                    [
                        'dosya' => $orijinalAd,
                        'hata'  => $e->getMessage(),
                    ]
                );
            }
        }


        $text =
            trim(
                implode(
                    "\n",
                    $sayfaMetinleri
                )
            );


        /*
        |--------------------------------------------------------------------------
        | 2. METİN YOKSA OCR
        |--------------------------------------------------------------------------
        */

        if ($text === '') {

            $ocrKlasoru =
                storage_path('app/private/ocr');


            if (!file_exists($ocrKlasoru)) {

                mkdir(
                    $ocrKlasoru,
                    0777,
                    true
                );
            }


            $outputPrefix =
                $ocrKlasoru .
                '/sayfa_' .
                uniqid();


            $wslPdfPath =
                windowsToWsl($pdfPath);


            $wslOutputPrefix =
                windowsToWsl($outputPrefix);


            $command =
                'wsl pdftoppm -png -r 200 ' .
                escapeshellarg($wslPdfPath) .
                ' ' .
                escapeshellarg($wslOutputPrefix);


            exec(
                $command . ' 2>&1',
                $output,
                $returnCode
            );


            $sayfalar =
                glob(
                    $outputPrefix . '-*.png'
                );


            natsort($sayfalar);


            if (
                $returnCode !== 0 ||
                empty($sayfalar)
            ) {

                Log::error(
                    'PDF -> PNG dönüştürme başarısız',
                    [
                        'dosya' =>
                        $orijinalAd,

                        'command' =>
                        $command,

                        'returnCode' =>
                        $returnCode,

                        'output' =>
                        $output,
                    ]
                );

                return [

                    'ok' => false,

                    'adi' =>
                    $orijinalAd,

                    'sebep' =>
                    'PDF sayfalara dönüştürülemedi (OCR aracı çalışmıyor olabilir).',

                ];
            }


            $text = '';

            $sayfaMetinleri = [];


            try {

                foreach ($sayfalar as $sayfaIndex => $sayfa) {

                    $sayfaNo = $sayfaIndex + 1;


                    $wslSayfa =
                        windowsToWsl(
                            realpath($sayfa)
                        );


                    $sayfaMetni =
                        shell_exec(
                            'wsl tesseract ' .
                                escapeshellarg($wslSayfa) .
                                ' stdout -l tur 2>&1'
                        );


                    if ($sayfaMetni) {

                        $sayfaMetinleri[$sayfaNo] =
                            trim($sayfaMetni);

                        $text .=
                            "\n" .
                            $sayfaMetni;
                    }
                }
            } finally {

                /*
                | OCR geçici PNG dosyalarını temizle
                */

                foreach ($sayfalar as $sayfa) {

                    if (file_exists($sayfa)) {

                        unlink($sayfa);
                    }
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | 3. UTF-8 TEMİZLİĞİ
        |--------------------------------------------------------------------------
        */

        foreach (
            $sayfaMetinleri
            as $sayfaNo => $sayfaMetni
        ) {

            $temiz =
                iconv(
                    'UTF-8',
                    'UTF-8//IGNORE',
                    $sayfaMetni
                );

            $sayfaMetinleri[$sayfaNo] =
                $temiz === false
                ? ''
                : trim($temiz);
        }


        // Temizlik sonrası tamamen boş kalan sayfaları at
        $sayfaMetinleri =
            array_filter(
                $sayfaMetinleri,
                fn($metin) => $metin !== ''
            );


        if (empty($sayfaMetinleri)) {

            return [

                'ok' => false,

                'adi' =>
                $orijinalAd,

                'sebep' =>
                'Okunabilir metin bulunamadı (görüntü tabanlı PDF).',

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | 4. CHUNK OLUŞTUR
        |--------------------------------------------------------------------------
        | Her sayfa AYRI AYRI chunk'lanır, böylece her chunk hangi sayfadan
        | geldiğini bilir (kaynak gösterimi için).
        |--------------------------------------------------------------------------
        */

        $chunks = [];

        /*
        | Eskiden 1000/200'dü. Bir puanlama tablosu / kriter listesi gibi
        | uzun bir blok 1000 karakteri geçince ikiye bölünüyordu; arama
        | sadece ilk parçayı bulursa ikinci yarıdaki kriterler (ör. Figma,
        | GitHub maddeleri) prompt'a hiç girmiyordu. Daha büyük chunk +
        | daha büyük overlap, bu tür listelerin tek parça kalma ihtimalini
        | artırır.
        */

        $chunkSize = 1600;

        $overlapSize = 300;


        foreach (
            $sayfaMetinleri
            as $sayfaNo => $sayfaMetni
        ) {

            $start = 0;

            $length = strlen($sayfaMetni);


            while ($start < $length) {

                $chunk =
                    substr(
                        $sayfaMetni,
                        $start,
                        $chunkSize
                    );


                if (
                    $start + $chunkSize <
                    $length
                ) {

                    $lastSpace =
                        strrpos(
                            $chunk,
                            ' '
                        );


                    if ($lastSpace !== false) {

                        $chunk =
                            substr(
                                $chunk,
                                0,
                                $lastSpace
                            );
                    }
                }


                $chunk =
                    iconv(
                        'UTF-8',
                        'UTF-8//IGNORE',
                        trim($chunk)
                    );


                if (
                    $chunk !== false &&
                    trim($chunk) !== ''
                ) {

                    $chunks[] = [

                        'metin' =>
                        trim($chunk),

                        'sayfa' =>
                        $sayfaNo,

                    ];
                }


                $start +=
                    (
                        $chunkSize -
                        $overlapSize
                    );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | 5. PDF'Yİ DB'YE KAYDET
        |--------------------------------------------------------------------------
        */

        $pdfKayit = Pdf::create([

            'user_id' =>
            Auth::id(),

            'dosya_adi' =>
            $orijinalAd,

            'yol' =>
            $yol,

        ]);


        /*
        |--------------------------------------------------------------------------
        | 6. EMBEDDING + WEAVIATE
        |--------------------------------------------------------------------------
        */

        foreach (
            $chunks
            as $index => $chunk
        ) {

            try {

                $embeddingResponse =
                    Http::timeout(120)->post(

                        'http://localhost:11434/api/embed',

                        [
                            'model' =>
                            'nomic-embed-text:latest',

                            'input' =>
                            $chunk['metin'],
                        ]

                    );
            } catch (
                ConnectionException $e
            ) {

                Log::error(
                    'Ollama embedding bağlantı hatası',
                    [
                        'dosya' =>
                        $orijinalAd,

                        'hata' =>
                        $e->getMessage(),
                    ]
                );

                return [

                    'ok' => false,

                    'adi' =>
                    $orijinalAd,

                    'sebep' =>
                    'Embedding servisine (Ollama) bağlanılamadı. Docker Desktop açık mı ve Ollama çalışıyor mu kontrol edin (terminalde: docker ps).',

                ];
            }


            if (
                $embeddingResponse->failed()
            ) {

                return [

                    'ok' => false,

                    'adi' =>
                    $orijinalAd,

                    'sebep' =>
                    'Embedding oluşturulurken hata oluştu.',

                ];
            }


            $embedding =
                $embeddingResponse
                ->json('embeddings.0');


            if (!$embedding) {

                return [

                    'ok' => false,

                    'adi' =>
                    $orijinalAd,

                    'sebep' =>
                    'Embedding verisi alınamadı.',

                ];
            }


            try {

                $weaviateResponse =
                    Http::timeout(120)->post(

                        'http://localhost:8080/v1/objects',

                        [

                            'class' =>
                            'PdfChunk',

                            'properties' => [

                                'pdf_adi' =>
                                $orijinalAd,

                                'pdf_id' =>
                                $pdfKayit->id,

                                'chunk' =>
                                $chunk['metin'],

                                'chunk_index' =>
                                $index,

                                'sayfa' =>
                                $chunk['sayfa'],

                            ],

                            'vector' =>
                            $embedding,

                        ]

                    );
            } catch (
                ConnectionException $e
            ) {

                Log::error(
                    'Weaviate bağlantı hatası (kayıt)',
                    [
                        'dosya' =>
                        $orijinalAd,

                        'hata' =>
                        $e->getMessage(),
                    ]
                );

                return [

                    'ok' => false,

                    'adi' =>
                    $orijinalAd,

                    'sebep' =>
                    'Vector veritabanına (Weaviate) bağlanılamadı. Docker Desktop açık mı ve Weaviate container\'ı çalışıyor mu kontrol edin (terminalde: docker ps; durmuşsa: docker start weaviate-weaviate-1).',

                ];
            }


            if (
                $weaviateResponse->failed()
            ) {

                return [

                    'ok' => false,

                    'adi' =>
                    $orijinalAd,

                    'sebep' =>
                    'Weaviate kayıt hatası.',

                ];
            }
        }


        return [

            'ok' =>
            true,

            'id' =>
            $pdfKayit->id,

            'adi' =>
            $orijinalAd,

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | PDF YÜKLEME
    |--------------------------------------------------------------------------
    | Çoklu PDF destekler.
    |
    | Yeni yüklenen PDF'ler mevcut aktif PDF listesine EKLENİR.
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/pdf-yukle',
        function (
            Illuminate\Http\Request $request
        ) {

            $request->validate([

                'pdf' =>
                'required|array|min:1|max:10',

                'pdf.*' =>
                'mimes:pdf|max:10240',

            ]);


            $dosyalar =
                $request->file('pdf');


            $basarili = [];

            $hatalar = [];


            foreach (
                $dosyalar
                as $dosya
            ) {

                /*
                | PDF magic-byte kontrolü
                */

                $ilkBaytlar =
                    file_get_contents(
                        $dosya->getRealPath(),
                        false,
                        null,
                        0,
                        5
                    );


                if (
                    $ilkBaytlar !== '%PDF-'
                ) {

                    $hatalar[] =
                        $dosya->getClientOriginalName() .
                        ': Geçerli bir PDF dosyası değil.';

                    continue;
                }


                $sonuc =
                    pdfDosyasiniIsle(
                        $dosya
                    );


                if (
                    $sonuc['ok']
                ) {

                    $basarili[] = [

                        'id' =>
                        $sonuc['id'],

                        'adi' =>
                        $sonuc['adi'],

                    ];
                } else {

                    $hatalar[] =
                        $sonuc['adi'] .
                        ': ' .
                        $sonuc['sebep'];
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Hiçbir PDF yüklenemediyse
            |--------------------------------------------------------------------------
            */

            if (
                empty($basarili)
            ) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'Hiçbir PDF yüklenemedi. ' .
                            implode(
                                ' | ',
                                $hatalar
                            )
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | MEVCUT AKTİF PDF'LERİ KORU
            |--------------------------------------------------------------------------
            | Yeni PDF'ler mevcut listeye eklenir.
            |--------------------------------------------------------------------------
            */

            $mevcutPdfler =
                session(
                    'pdfler',
                    []
                );


            foreach (
                $basarili
                as $yeni
            ) {

                $zatenVarMi =
                    collect(
                        $mevcutPdfler
                    )->contains(
                        fn($p) =>
                        (int) $p['id'] ===
                            (int) $yeni['id']
                    );


                if (
                    !$zatenVarMi
                ) {

                    $mevcutPdfler[] =
                        $yeni;
                }
            }


            session([
                'pdfler' =>
                $mevcutPdfler
            ]);


            /*
            |--------------------------------------------------------------------------
            | BAŞARI MESAJI
            |--------------------------------------------------------------------------
            */

            $mesaj =
                count($basarili) .
                ' PDF başarıyla yüklendi. Artık soru sorabilirsiniz.';


            if (
                !empty($hatalar)
            ) {

                $mesaj .=
                    ' (Yüklenemeyenler: ' .
                    implode(
                        ' | ',
                        $hatalar
                    ) .
                    ')';
            }


            return redirect('/')
                ->with(
                    'cevap',
                    $mesaj
                )
                ->with(
                    'hata',
                    false
                );
        }
    )->middleware(
        'throttle:10,1'
    );


    /*
    |--------------------------------------------------------------------------
    | AKTİF PDF'Yİ KALDIR
    |--------------------------------------------------------------------------
    | ÖNEMLİ:
    |
    | Bu route PDF'yi DB'den SİLMEZ.
    |
    | Sadece session'daki aktif PDF listesinden çıkarır.
    |
    | Böylece:
    | - PDF DB'de kalır
    | - Geçmiş kayıtları kalır
    | - Weaviate verileri kalır
    | - PDF sadece aktif soru-cevap listesinden çıkar
    |--------------------------------------------------------------------------
    */

    Route::delete(
        '/pdf-kaldir/{id}',
        function (int $id) {

            /*
            | Önce PDF'nin gerçekten giriş yapan kullanıcıya
            | ait olduğunu kontrol et.
            */

            $pdf =
                Pdf::where(
                    'id',
                    $id
                )
                ->where(
                    'user_id',
                    Auth::id()
                )
                ->first();


            /*
            | PDF bulunamadıysa
            */

            if (!$pdf) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'PDF bulunamadı veya bu PDF üzerinde işlem yapma yetkiniz yok.'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            /*
            | Session'daki aktif PDF listesini al
            */

            $mevcutPdfler =
                session(
                    'pdfler',
                    []
                );


            /*
            | Seçilen PDF'yi sadece aktif listeden çıkar
            */

            $mevcutPdfler =
                collect(
                    $mevcutPdfler
                )
                ->reject(
                    function ($p) use ($id) {

                        return
                            (int) $p['id'] ===
                            (int) $id;
                    }
                )
                ->values()
                ->all();


            /*
            | Session'ı güncelle
            */

            session([
                'pdfler' =>
                $mevcutPdfler
            ]);


            /*
            | Kullanıcıya bilgi ver
            */

            return redirect('/')
                ->with(
                    'cevap',
                    '"' .
                        $pdf->dosya_adi .
                        '" aktif dokümanlardan kaldırıldı.'
                )
                ->with(
                    'hata',
                    false
                );
        }
    )->middleware(
        'throttle:20,1'
    );


    /*
    |--------------------------------------------------------------------------
    | SORU SOR
    |--------------------------------------------------------------------------
    | Aktif PDF'lerin tamamında arama yapar.
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/soru',
        function (
            Illuminate\Http\Request $request
        ) {

            $request->validate([

                'soru' =>
                'required',

                'model' =>
                'nullable|in:gemini,grok',

            ]);


            /*
            |--------------------------------------------------------------------------
            | MODEL SEÇİMİ
            |--------------------------------------------------------------------------
            */

            $selectedModel =
                $request->input(
                    'model',
                    'gemini'
                ) === 'grok'
                ? 'groq'
                : 'gemini';


            /*
            |--------------------------------------------------------------------------
            | SORU
            |--------------------------------------------------------------------------
            */

            $question =
                iconv(
                    'UTF-8',
                    'UTF-8//IGNORE',
                    $request->input('soru')
                );


            if (
                $question === false
            ) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'Soru okunamadı.'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            $question =
                trim($question);


            /*
            |--------------------------------------------------------------------------
            | AKTİF PDF'LER
            |--------------------------------------------------------------------------
            */

            $pdfler =
                session(
                    'pdfler',
                    []
                );


            if (
                empty($pdfler)
            ) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'Önce en az bir PDF yüklemeniz gerekiyor.'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | GÜVENLİK
            |--------------------------------------------------------------------------
            | Session'daki PDF ID'lerinin kullanıcıya ait olduğunu doğrula.
            |--------------------------------------------------------------------------
            */

            $sahipOlunanIdler =
                Pdf::where(
                    'user_id',
                    Auth::id()
                )
                ->whereIn(
                    'id',
                    collect($pdfler)
                        ->pluck('id')
                )
                ->pluck('id')
                ->all();


            $pdfler =
                collect($pdfler)
                ->filter(
                    fn($p) =>
                    in_array(
                        $p['id'],
                        $sahipOlunanIdler
                    )
                )
                ->values()
                ->all();


            if (
                empty($pdfler)
            ) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'Seçili PDF\'lere erişim yetkiniz yok. Lütfen yeniden yükleyin.'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | SORU EMBEDDING
            |--------------------------------------------------------------------------
            */

            try {

                $embeddingResponse =
                    Http::timeout(120)->post(

                        'http://localhost:11434/api/embed',

                        [
                            'model' =>
                            'nomic-embed-text:latest',

                            'input' =>
                            $question,
                        ]

                    );
            } catch (
                ConnectionException $e
            ) {

                Log::error(
                    'Ollama embedding bağlantı hatası (soru)',
                    [
                        'hata' =>
                        $e->getMessage(),
                    ]
                );

                return redirect('/')
                    ->with(
                        'cevap',
                        'Embedding servisine (Ollama) bağlanılamadı. Docker Desktop açık mı ve Ollama çalışıyor mu kontrol edin (terminalde: docker ps).'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            if (
                $embeddingResponse->failed()
            ) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'Soru embedding hatası.'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            $soruEmbedding =
                $embeddingResponse
                ->json('embeddings.0');


            if (
                !$soruEmbedding
            ) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'Soru embedding verisi alınamadı.'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | WEAVIATE ARAMA
            |--------------------------------------------------------------------------
            | Her PDF için ayrı arama yapılır.
            |--------------------------------------------------------------------------
            */

            $vector =
                implode(
                    ',',
                    $soruEmbedding
                );


            /*
            |--------------------------------------------------------------------------
            | PDF BAŞINA CHUNK LİMİTİ
            |--------------------------------------------------------------------------
            | Eskiden 4/3/2/1'di. Bir sayfadaki uzun kriter listesi/tablo,
            | en yüksek benzerlikli TEK chunk'a sığmayabiliyor; düşük limit
            | yüzden listenin bir kısmı hiç getirilmiyordu. Sayıları artırdık
            | -daha kapsamlı ama biraz daha yavaş cevap pahasına.
            |--------------------------------------------------------------------------
            */

            $pdfSayisi =
                count($pdfler);


            $pdfBasinaLimit =
                match (true) {

                    $pdfSayisi <= 3 =>
                    7,

                    $pdfSayisi <= 6 =>
                    5,

                    $pdfSayisi <= 10 =>
                    3,

                    default =>
                    2,
                };


            $sonuclar = [];

            $basarisizPdfler = [];


            /*
            |--------------------------------------------------------------------------
            | HER PDF İÇİN WEAVIATE SORGUSU (PARALEL)
            |--------------------------------------------------------------------------
            | Eskiden her PDF için sırayla (foreach + await) sorgu atılıyordu;
            | 5 aktif PDF varsa 5 network round-trip art arda bekleniyordu.
            | Http::pool() ile hepsi AYNI ANDA gönderilir, toplam süre
            | "en yavaş tek sorgu" kadar olur, "sorguların toplamı" kadar değil.
            |--------------------------------------------------------------------------
            */

            $pdfSorgulari = [];

            foreach (
                $pdfler
                as $p
            ) {

                $pdfId =
                    (int) $p['id'];

                $pdfSorgulari[$pdfId] = <<<GRAPHQL
{
    Get {
        PdfChunk(
            nearVector: { vector: [$vector] }
            where: {
                path: ["pdf_id"]
                operator: Equal
                valueNumber: {$pdfId}
            }
            limit: {$pdfBasinaLimit}
        ) {
            pdf_adi
            pdf_id
            chunk
            chunk_index
            sayfa
            _additional { distance }
        }
    }
}
GRAPHQL;
            }


            $havuzCevaplari =
                Http::pool(
                    function ($pool) use ($pdfSorgulari) {

                        $istekler = [];

                        foreach (
                            $pdfSorgulari
                            as $pdfId => $graphql
                        ) {

                            $istekler[] =
                                $pool
                                ->as((string) $pdfId)
                                ->timeout(60)
                                ->post(
                                    'http://localhost:8080/v1/graphql',
                                    ['query' => $graphql]
                                );
                        }

                        return $istekler;
                    }
                );


            $baglantiHatasiVarMi = false;


            foreach (
                $pdfler
                as $p
            ) {

                $pdfId =
                    (int) $p['id'];

                $cevap =
                    $havuzCevaplari[(string) $pdfId]
                    ?? null;


                /*
                | Pool içindeki bir istek bağlantı hatası (Weaviate'e hiç
                | ulaşılamadı) verirse Response yerine Throwable döner.
                */

                if ($cevap instanceof \Throwable) {

                    Log::warning(
                        'Bir PDF için Weaviate bağlantı hatası, atlanıyor',
                        [
                            'pdf_id' => $pdfId,
                            'hata'   => $cevap->getMessage(),
                        ]
                    );

                    $baglantiHatasiVarMi = true;

                    $basarisizPdfler[] =
                        $p['adi'];

                    continue;
                }


                if (
                    !$cevap ||
                    $cevap->failed() ||
                    $cevap->json('errors')
                ) {

                    Log::warning(
                        'Bir PDF için Weaviate araması başarısız, atlanıyor',
                        [
                            'pdf_id' =>
                            $pdfId,

                            'errors' =>
                            $cevap
                                ? $cevap->json('errors')
                                : null,
                        ]
                    );


                    $basarisizPdfler[] =
                        $p['adi'];

                    continue;
                }


                $pdfSonuclari =
                    $cevap
                    ->json(
                        'data.Get.PdfChunk'
                    ) ?? [];


                $sonuclar =
                    array_merge(
                        $sonuclar,
                        $pdfSonuclari
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | HİÇBİR SONUÇ YOKSA VE HEPSİ BAĞLANTI HATASIYSA
            |--------------------------------------------------------------------------
            */

            if (
                empty($sonuclar) &&
                $baglantiHatasiVarMi &&
                count($basarisizPdfler) === count($pdfler)
            ) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'Vector veritabanına (Weaviate) bağlanılamadı. Docker Desktop açık mı ve Weaviate container\'ı çalışıyor mu kontrol edin (terminalde: docker ps; durmuşsa: docker start weaviate-weaviate-1).'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | BAŞARISIZ PDF'LERİ LOG'LA
            |--------------------------------------------------------------------------
            */

            if (
                !empty($basarisizPdfler)
            ) {

                Log::warning(
                    'Bazı PDF\'ler aramaya dahil edilemedi',
                    [
                        'pdfler' =>
                        $basarisizPdfler,
                    ]
                );
            }


            if (
                empty($sonuclar)
            ) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'Yüklü PDF\'lerde soruyla ilgili bilgi bulunamadı.'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | CHUNK'LARI PROMPT İÇİN BİRLEŞTİR
            |--------------------------------------------------------------------------
            */

            /*
            | Chunk'lar ingest sırasında zaten en fazla ~1600 karakter olacak
            | şekilde bölünüyor (bkz. $chunkSize). Buradaki limit ondan düşükse
            | chunk'ın sonu -örneğin bir tablonun ikinci yarısı- prompt'a hiç
            | girmeden sessizce kesiliyordu. Chunk boyutundan büyük tutuyoruz
            | ki gerçek bir kesme olmasın.
            */

            $chunkKarakterLimiti =
                2000;


            $ilgiliMetin = '';

            $kullanilanPdfler = [];


            foreach (
                $sonuclar
                as $sonuc
            ) {

                if (
                    !isset(
                        $sonuc['chunk']
                    )
                ) {

                    continue;
                }


                $kaynak =
                    $sonuc['pdf_adi']
                    ?? 'bilinmeyen';

                $sayfaNo =
                    $sonuc['sayfa']
                    ?? null;

                $kaynakEtiketi =
                    $sayfaNo
                    ? "{$kaynak}, Sayfa {$sayfaNo}"
                    : $kaynak;


                $chunkMetni =
                    mb_substr(
                        $sonuc['chunk'],
                        0,
                        $chunkKarakterLimiti
                    );


                $ilgiliMetin .=
                    "\n\n[Kaynak: {$kaynakEtiketi}]\n" .
                    $chunkMetni;


                if (
                    !in_array(
                        $kaynak,
                        $kullanilanPdfler
                    )
                ) {

                    $kullanilanPdfler[] =
                        $kaynak;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | AI PROMPT
            |--------------------------------------------------------------------------
            */

            $prompt = <<<PROMPT
Sen PDF içeriğine göre çalışan Türkçe bir soru-cevap asistanısın.
Aşağıda birden fazla PDF'den getirilmiş bölümler var; her bölümün başında
hangi PDF'den ve hangi SAYFADAN geldiği [Kaynak: dosya_adi.pdf, Sayfa X]
şeklinde belirtiliyor.

ÇOK ÖNEMLİ KURALLAR:

1. Kullanıcının sorduğu soru numarasını dikkatlice belirle.
2. Örneğin kullanıcı "5. soru" diyorsa SADECE PDF'deki "Soru 5" başlığının altında bulunan içeriği kullan.
3. Başka bir sorunun içeriğini Soru 5 ile kesinlikle karıştırma.
4. Soru numarasını tahmin etme veya değiştirme.
5. Kullanıcı bir programlama sorusunun çözümünü isterse, ilgili sorunun TÜM maddelerini dikkate al.
6. PDF'de bulunmayan şartları, kelime veya bilgileri kesinlikle ekleme; uydurma.
7. PDF'deki şartlardan hiçbirini atlama.
8. Genel cevabı Türkçe cümlelerle yaz. PDF orijinal dilde (İngilizce, vb.) olsa bile Türkçe bir dille açıkla — ama teknik terim, kavram veya "önemli kelimeler" isteniyorsa, terimi PDF'deki ORİJİNAL haliyle yaz ve yanına parantez içinde Türkçe karşılığını ekle. Örnek: "risk analysis (risk analizi)", "schedule slippage (takvim gecikmesi)". Bir İngilizce kelimeye Türkçe ek getirerek karma cümle kurma.
9. Gereksiz selamlama, "sorunuzu tekrar sorun", "cevaplayalım", "cevap verelim" gibi giriş ifadeleri kullanma. Doğrudan cevaba başla.
10. Kodları Markdown kod bloğunda ve düzgün girintilerle göster.
11. Cevabında bu kuralları, talimat metnini veya kural numaralarını asla tekrar etme. İlk kelimenden itibaren doğrudan cevaba başla.
12. Verilen bölümlerde sorunun cevabı yoksa SADECE "Bu bilgi PDF içerisinde bulunmuyor." yaz.
13. Kelime listesi istendiğinde SADECE aşağıdaki bölümlerde gerçekten geçen kelimeleri ver. Bölümlerde geçmeyen kelime uydurma; listeyi kısalt, ama uydurma.
14. Kullanıcı "kaç soru var", "toplam kaç", "kaç tane" gibi SAYIM gerektiren bir soru soruyorsa: Bunun yerine SADECE şunu yaz: "Gördüğüm bölümlerde [X] adet soru var."
15. Cevap içinde bahsettiğin HER bilgiden hemen sonra parantez içinde o bilginin geldiği [Kaynak: ...] etiketindeki dosya adını VE sayfa numarasını "(dosya_adi.pdf, Sayfa X)" biçiminde belirt. Birden fazla bilgi aynı kaynaktan geliyorsa her seferinde tekrar yazmaktan çekinme.
16. Cevabın sonunda ayrı bir satırda "Kaynak:" diyerek cevapta kullanılan TÜM dosya adı ve sayfa numaralarını (ör. "sorularDers12.pdf – Sayfa 3, fatura_satis_1.pdf – Sayfa 1") listele.
17. Sana verilen bölümlerde SATIR numarası bilgisi YOK, sadece SAYFA numarası var. Bu yüzden asla "şu satırda" gibi bir ifade kullanma veya satır numarası uydurma; sadece sayfa numarasını belirt.
18. Kullanıcı bir LİSTE, KRİTER, RUBRİK, PUANLAMA TABLOSU, MADDE MADDE ŞARTLAR veya "neler var / hepsini say" tarzı bir şey soruyorsa: verilen bölümlerde geçen HER maddeyi/satırı/kriteri tek tek listele. Sadece ilk birkaçını yazıp "gibi maddeler de var" diye özetleme veya bir kısmını atlama; hepsini yaz.
19. Eğer verilen bölümlerde yüzdelik/puanlık bir dağılım varsa (ör. her kriterin yanında %15, %10 gibi bir pay), cevabı vermeden önce zihninde bu yüzdelerin toplamını kontrol et. Cevabına sadece toplamın bir kısmına denk gelen kriterleri değil, TÜMÜNÜ dahil et.
20. Eğer bir listenin/tablonun sadece bir kısmının verilen bölümlerde yer aldığını, geri kalanının muhtemelen başka bir sayfada/bölümde olduğunu düşünüyorsan bunu cevabın sonunda açıkça belirt: "Not: Verilen bölümlerde bu listenin tamamı olmayabilir, sadece görebildiğim kısmı yukarıda." Bunu uydurma bir liste eklemek yerine kullan.

PDF'DEN GETİRİLEN İLGİLİ BÖLÜMLER:

$ilgiliMetin


KULLANICININ SORUSU:

$question


CEVAP:
PROMPT;


            /*
            |--------------------------------------------------------------------------
            | GROQ
            |--------------------------------------------------------------------------
            */

            $groqDene =
                function () use ($prompt) {

                    $groqApiKey =
                        config(
                            'services.groq.key'
                        );


                    if (
                        !$groqApiKey
                    ) {

                        Log::warning(
                            'GROQ_API_KEY tanımlı değil, Groq atlanıyor.'
                        );

                        return null;
                    }


                    try {

                        $groqResponse =
                            Http::timeout(120)
                            ->withToken(
                                $groqApiKey
                            )
                            ->post(

                                'https://api.groq.com/openai/v1/chat/completions',

                                [

                                    'model' =>
                                    'openai/gpt-oss-120b',

                                    'messages' => [

                                        [
                                            'role' =>
                                            'user',

                                            'content' =>
                                            $prompt,
                                        ],

                                    ],

                                    'temperature' =>
                                    0.2,

                                ]

                            );


                        if (
                            $groqResponse->successful()
                        ) {

                            return
                                $groqResponse
                                ->json(
                                    'choices.0.message.content'
                                );
                        }


                        Log::warning(
                            'Groq API hatası',
                            [
                                'status' =>
                                $groqResponse->status(),

                                'body' =>
                                $groqResponse->body(),
                            ]
                        );
                    } catch (
                        ConnectionException $e
                    ) {

                        Log::warning(
                            'Groq bağlantı hatası',
                            [
                                'hata' =>
                                $e->getMessage(),
                            ]
                        );
                    }


                    return null;
                };


            /*
            |--------------------------------------------------------------------------
            | GEMINI
            |--------------------------------------------------------------------------
            */

            $geminiDene =
                function () use ($prompt) {

                    $geminiApiKey =
                        config(
                            'services.gemini.key'
                        );


                    if (
                        !$geminiApiKey
                    ) {

                        Log::warning(
                            'GEMINI_API_KEY tanımlı değil, Gemini atlanıyor.'
                        );

                        return null;
                    }


                    try {

                        $geminiResponse =
                            Http::timeout(120)->post(

                                'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' .
                                    $geminiApiKey,

                                [

                                    'contents' => [

                                        [
                                            'parts' => [

                                                [
                                                    'text' =>
                                                    $prompt,
                                                ],

                                            ],
                                        ],

                                    ],

                                ]

                            );


                        if (
                            $geminiResponse->successful()
                        ) {

                            return
                                $geminiResponse
                                ->json(
                                    'candidates.0.content.parts.0.text'
                                );
                        }


                        Log::warning(
                            'Gemini API hatası',
                            [

                                'status' =>
                                $geminiResponse->status(),

                                'body' =>
                                $geminiResponse->body(),

                            ]
                        );
                    } catch (
                        ConnectionException $e
                    ) {

                        Log::warning(
                            'Gemini bağlantı hatası',
                            [

                                'hata' =>
                                $e->getMessage(),

                            ]
                        );
                    }


                    return null;
                };


            /*
            |--------------------------------------------------------------------------
            | AI MODELİ ÇALIŞTIR
            |--------------------------------------------------------------------------
            */

            $answer = null;

            $kullanilanAi = null;


            if (
                $selectedModel === 'groq'
            ) {

                $answer =
                    $groqDene();


                if ($answer) {

                    $kullanilanAi =
                        'Groq';
                } else {

                    Log::warning(
                        'Groq başarısız (birincil seçim), Gemini\'ye geçiliyor.'
                    );


                    $answer =
                        $geminiDene();


                    if ($answer) {

                        $kullanilanAi =
                            'Gemini (yedek)';
                    }
                }
            } else {

                $answer =
                    $geminiDene();


                if ($answer) {

                    $kullanilanAi =
                        'Gemini';
                } else {

                    Log::warning(
                        'Gemini başarısız (birincil seçim), Groq\'a geçiliyor.'
                    );


                    $answer =
                        $groqDene();


                    if ($answer) {

                        $kullanilanAi =
                            'Groq (yedek)';
                    }
                }
            }


            /*
            |--------------------------------------------------------------------------
            | AI CEVAP VERMEDİ
            |--------------------------------------------------------------------------
            */

            if (!$answer) {

                return redirect('/')
                    ->with(
                        'cevap',
                        'AI servislerine bağlanılamadı (Groq ve Gemini ikisi de başarısız).'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            Log::info(
                'Soru cevaplandı',
                [
                    'kullanilan_ai' =>
                    $kullanilanAi,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | SORUYU DB'YE KAYDET
            |--------------------------------------------------------------------------
            | Question tablosu tek pdf_id tuttuğu için çoklu PDF sorgusunda
            | ilk kullanılan PDF kaydediliyor.
            |--------------------------------------------------------------------------
            */

            $ilkPdf =
                collect(
                    $pdfler
                )->firstWhere(
                    'adi',
                    $kullanilanPdfler[0] ?? null
                );


            Question::create([

                'user_id' =>
                Auth::id(),

                'pdf_id' =>
                $ilkPdf['id']
                    ?? $pdfler[0]['id'],

                'soru' =>
                $question,

                'cevap' =>
                $answer,

            ]);


            /*
            |--------------------------------------------------------------------------
            | CEVAP SAYFASINA DÖN
            |--------------------------------------------------------------------------
            */

            return view(
                'pdf',
                [

                    'answer' =>
                    $answer,

                    'question' =>
                    $question,

                    'selectedModel' =>
                    $request->input(
                        'model',
                        'gemini'
                    ),

                    'kullanilanAi' =>
                    $kullanilanAi,

                ]
            );
        }
    )->middleware(
        'throttle:15,1'
    );


    /*
    |--------------------------------------------------------------------------
    | GEÇMİŞ
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/gecmis',
        function () {

            $sorular =
                Question::with('pdf')

                ->where(
                    'user_id',
                    Auth::id()
                )

                ->latest()

                ->get()

                ->groupBy(
                    function ($soru) {

                        return
                            $soru->created_at
                            ->format(
                                'd.m.Y'
                            );
                    }
                );


            return view(
                'gecmis',
                compact(
                    'sorular'
                )
            );
        }
    );

    /*
|--------------------------------------------------------------------------
| SORUYU GEÇMİŞTEN SİL
|--------------------------------------------------------------------------
*/

    Route::delete(
        '/soru-sil/{id}',
        function (int $id) {

            $soru =
                Question::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();


            if (!$soru) {

                return redirect('/gecmis')
                    ->with(
                        'cevap',
                        'Soru bulunamadı veya silme yetkiniz yok.'
                    )
                    ->with(
                        'hata',
                        true
                    );
            }


            $soru->delete();


            return redirect('/gecmis')
                ->with(
                    'cevap',
                    'Soru geçmişten silindi.'
                )
                ->with(
                    'hata',
                    false
                );
        }
    )->middleware(
        'throttle:20,1'
    );


    /*
    |--------------------------------------------------------------------------
    | YENİ PDF
    |--------------------------------------------------------------------------
    | Aktif PDF listesini tamamen temizler.
    |
    | DB'deki PDF kayıtları silinmez.
    | Geçmiş kayıtları silinmez.
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/yeni-pdf',
        function () {

            session()->forget([

                'pdfler',

                'cevap',

                'hata',

            ]);


            return redirect('/');
        }
    );
});
