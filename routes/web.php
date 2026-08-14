<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
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
| AUTH ROUTE'LARI (giriş gerektirmez)
|--------------------------------------------------------------------------
*/
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register']);

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');


/*
|--------------------------------------------------------------------------
| KORUNAN ROUTE'LAR (giriş zorunlu)
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
    | PDF YÜKLEME
    |--------------------------------------------------------------------------
    */
    Route::post('/pdf-yukle', function (Illuminate\Http\Request $request) {

        $request->validate([
            'pdf' => 'required|mimes:pdf|max:10240',
        ]);

        $dosya = $request->file('pdf');
        $yol   = $dosya->store('pdfler');
        $pdfPath = storage_path('app/private/' . $yol);


        /*
        |----------------------------------------------------------------------
        | 1. Smalot ile metin çıkar
        |----------------------------------------------------------------------
        */
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($pdfPath);
        $text   = trim($pdf->getText());


        /*
        |----------------------------------------------------------------------
        | 2. Metin yoksa OCR'a geç
        |----------------------------------------------------------------------
        */
        if ($text === '') {

            $ocrKlasoru = storage_path('app/private/ocr');

            if (!file_exists($ocrKlasoru)) {
                mkdir($ocrKlasoru, 0777, true);
            }

            $outputPrefix    = $ocrKlasoru . '/sayfa';
            $wslPdfPath      = windowsToWsl($pdfPath);
            $wslOutputPrefix = windowsToWsl($outputPrefix);

            $command = 'wsl pdftoppm -png -r 200 '
                . escapeshellarg($wslPdfPath) . ' '
                . escapeshellarg($wslOutputPrefix);

            exec($command . ' 2>&1', $output, $returnCode);

            $sayfalar = glob($ocrKlasoru . '/sayfa-*.png');
            natsort($sayfalar);

            if ($returnCode !== 0 || empty($sayfalar)) {

                Log::error('PDF -> PNG dönüştürme başarısız', [
                    'command'    => $command,
                    'returnCode' => $returnCode,
                    'output'     => $output,
                ]);

                return redirect('/')->with('cevap',
                    'PDF sayfalara dönüştürülemedi. OCR aracı (pdftoppm) çalışmıyor olabilir.'
                )->with('hata', true);
            }

            $text = '';

            foreach ($sayfalar as $sayfa) {

                $wslSayfa = windowsToWsl(realpath($sayfa));

                $sayfaMetni = shell_exec(
                    'wsl tesseract ' . escapeshellarg($wslSayfa) . ' stdout -l tur 2>&1'
                );

                if ($sayfaMetni) {
                    $text .= "\n" . $sayfaMetni;
                }
            }

            foreach ($sayfalar as $sayfa) {
                if (file_exists($sayfa)) unlink($sayfa);
            }
        }


        /*
        |----------------------------------------------------------------------
        | 3. UTF-8 temizliği
        |----------------------------------------------------------------------
        */
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        $text = $text === false ? '' : trim($text);

        if ($text === '') {
            return redirect('/')->with('cevap',
                "Bu PDF'de okunabilir metin bulunamadı. Görüntü tabanlı PDF yüklediniz."
            )->with('hata', true);
        }


        /*
        |----------------------------------------------------------------------
        | 4. Chunk oluştur
        |----------------------------------------------------------------------
        */
        $chunks      = [];
        $chunkSize   = 1000;
        $overlapSize = 200;
        $start       = 0;
        $length      = strlen($text);

        while ($start < $length) {

            $chunk = substr($text, $start, $chunkSize);

            if ($start + $chunkSize < $length) {
                $lastSpace = strrpos($chunk, ' ');
                if ($lastSpace !== false) {
                    $chunk = substr($chunk, 0, $lastSpace);
                }
            }

            $chunk = iconv('UTF-8', 'UTF-8//IGNORE', trim($chunk));

            if ($chunk !== false && trim($chunk) !== '') {
                $chunks[] = trim($chunk);
            }

            $start += ($chunkSize - $overlapSize);
        }


        /*
        |----------------------------------------------------------------------
        | 5. Weaviate'a embedding + chunk kaydet
        |----------------------------------------------------------------------
        */
        foreach ($chunks as $index => $chunk) {

            $embeddingResponse = Http::timeout(120)->post(
                'http://localhost:11434/api/embed',
                ['model' => 'nomic-embed-text:latest', 'input' => $chunk]
            );

            if ($embeddingResponse->failed()) {
                return redirect('/')->with('cevap', 'Embedding oluşturulurken hata oluştu.')->with('hata', true);
            }

            $embedding = $embeddingResponse->json('embeddings.0');

            if (!$embedding) {
                return redirect('/')->with('cevap', 'Embedding verisi alınamadı.')->with('hata', true);
            }

            $weaviateResponse = Http::timeout(120)->post(
                'http://localhost:8080/v1/objects',
                [
                    'class'      => 'PdfChunk',
                    'properties' => [
                        'pdf_adi'     => $dosya->getClientOriginalName(),
                        'chunk'       => $chunk,
                        'chunk_index' => $index,
                    ],
                    'vector' => $embedding,
                ]
            );

            if ($weaviateResponse->failed()) {
                return redirect('/')->with('cevap', 'Weaviate kayıt hatası.')->with('hata', true);
            }
        }


        /*
        |----------------------------------------------------------------------
        | 6. DB'ye kaydet
        |----------------------------------------------------------------------
        */
        $pdfKayit = Pdf::create([
            'user_id'   => Auth::id(),
            'dosya_adi' => $dosya->getClientOriginalName(),
            'yol'       => $yol,
        ]);


        /*
        |----------------------------------------------------------------------
        | 7. Session
        |----------------------------------------------------------------------
        */
        session([
            'pdf_adi'      => $dosya->getClientOriginalName(),
            'pdf_metni'    => $text,
            'pdf_chunklar' => $chunks,
            'pdf_id'       => $pdfKayit->id,   /*soru kaydederken lazım*/
        ]);

        return redirect('/')->with('cevap', 'PDF başarıyla yüklendi. Artık soru sorabilirsiniz.');
    });


    /*
    |--------------------------------------------------------------------------
    | SORU SOR
    |--------------------------------------------------------------------------
    */
    Route::post('/soru', function (Illuminate\Http\Request $request) {

        $request->validate(['soru' => 'required']);

        $question = iconv('UTF-8', 'UTF-8//IGNORE', $request->input('soru'));

        if ($question === false) {
            return redirect('/')->with('cevap', 'Soru okunamadı.')->with('hata', true);
        }

        $question = trim($question);
        $pdfAdi   = session('pdf_adi');

        if (!$pdfAdi) {
            return redirect('/')->with('cevap', 'Önce bir PDF yüklemeniz gerekiyor.')->with('hata', true);
        }


        /*
        Soru embedding
        */
        $embeddingResponse = Http::timeout(120)->post(
            'http://localhost:11434/api/embed',
            ['model' => 'nomic-embed-text:latest', 'input' => $question]
        );

        if ($embeddingResponse->failed()) {
            return redirect('/')->with('cevap', 'Soru embedding hatası.')->with('hata', true);
        }

        $soruEmbedding = $embeddingResponse->json('embeddings.0');

        if (!$soruEmbedding) {
            return redirect('/')->with('cevap', 'Soru embedding verisi alınamadı.')->with('hata', true);
        }


        /*
        Weaviate arama
        */
        $vector        = implode(',', $soruEmbedding);
        $pdfAdiGraphQL = addslashes($pdfAdi);

        $graphql = <<<GRAPHQL
{
    Get {
        PdfChunk(
            nearVector: { vector: [$vector] }
            where: {
                path: ["pdf_adi"]
                operator: Equal
                valueText: "$pdfAdiGraphQL"
            }
            limit: 5
        ) {
            pdf_adi
            chunk
            chunk_index
            _additional { distance }
        }
    }
}
GRAPHQL;

        $weaviateResponse = Http::timeout(120)->post(
            'http://localhost:8080/v1/graphql',
            ['query' => $graphql]
        );

        if ($weaviateResponse->failed()) {
            return redirect('/')->with('cevap', 'Weaviate araması hatası.')->with('hata', true);
        }

        $sonuclar = $weaviateResponse->json('data.Get.PdfChunk');

        if (!$sonuclar) {
            return redirect('/')->with('cevap', 'PDF içerisinde soruyla ilgili bilgi bulunamadı.')->with('hata', true);
        }


        /*
        İlgili chunk'ları birleştir
        */
        $ilgiliMetin = '';
        foreach ($sonuclar as $sonuc) {
            if (!isset($sonuc['chunk'])) continue;
            $ilgiliMetin .= "\n\n" . $sonuc['chunk'];
        }


        /*
        Prompt
        */
        $prompt = <<<PROMPT
Sen PDF içeriğine göre çalışan Türkçe bir soru-cevap asistanısın.

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
11. Cevabında bu kuralları, talimat metnini veya kural numaralarını asla tekrar etme. İlk kelimenden itibaren doğrudan cevap ver.
12. Verilen bölümlerde sorunun cevabı yoksa SADECE "Bu bilgi PDF içerisinde bulunmuyor." yaz.
13. Kelime listesi istendiğinde SADECE aşağıdaki bölümlerde gerçekten geçen kelimeleri ver. Bölümlerde geçmeyen kelime uydurma; listeyi kısalt, ama uydurma.

PDF'DEN GETİRİLEN İLGİLİ BÖLÜMLER:

$ilgiliMetin


KULLANICININ SORUSU:

$question


CEVAP:
PROMPT;


        /*
        Llama3
        */
        $response = Http::timeout(120)->post(
            'http://localhost:11434/api/generate',
            ['model' => 'llama3:latest', 'prompt' => $prompt, 'stream' => false]
        );

        if ($response->failed()) {
            return redirect('/')->with('cevap', 'AI servisine bağlanırken hata oluştu.')->with('hata', true);
        }

        $answer = $response->json('response') ?? 'AI tarafından cevap alınamadı.';


        /*
        |----------------------------------------------------------------------
        | DB'ye kaydet
        |----------------------------------------------------------------------
        */
        Question::create([
            'user_id' => Auth::id(),
            'pdf_id'  => session('pdf_id'),
            'soru'    => $question,
            'cevap'   => $answer,
        ]);


        return view('pdf', [
            'answer'   => $answer,
            'question' => $question,
        ]);
    });


    /*
    |--------------------------------------------------------------------------
    | GEÇMİŞ
    |--------------------------------------------------------------------------
    */
    Route::get('/gecmis', function () {

        /*
        Giriş yapan kullanıcının tüm soruları, en yeni önce
        PDF adıyla birlikte çek
        */
        $sorular = Question::with('pdf')
            ->where('user_id', Auth::id())
            ->latest()
            ->paginate(15);

        return view('gecmis', compact('sorular'));
    });


    /*
    |--------------------------------------------------------------------------
    | YENİ PDF
    |--------------------------------------------------------------------------
    */
    Route::get('/yeni-pdf', function () {

        session()->forget([
            'pdf_adi',
            'pdf_metni',
            'pdf_chunklar',
            'pdf_id',
            'cevap',
            'hata',
        ]);

        return redirect('/');
    });

});