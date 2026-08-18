<?php

namespace App\Console\Commands;

use App\Models\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser;

class TestGroq extends Command
{
    protected $signature = 'test:groq';

    protected $description = 'Test Groq API with a registered PDF';

    public function handle()
    {
        $this->info('PDF kaydı aranıyor...');

        // Test edeceğimiz PDF
        $pdfAdi = 'sorularDers8.pdf';

        $pdfKayit = Pdf::where('dosya_adi', $pdfAdi)
            ->latest()
            ->first();

        if (!$pdfKayit) {
            $this->error("PDF bulunamadı: {$pdfAdi}");

            return Command::FAILURE;
        }

        $this->info('PDF kaydı bulundu.');
        $this->line('Dosya adı: ' . $pdfKayit->dosya_adi);
        $this->line('Kayıt yolu: ' . $pdfKayit->yol);

        // Gerçek PDF yolu
        $pdfPath = storage_path('app/private/' . $pdfKayit->yol);

        if (!file_exists($pdfPath)) {
            $this->error('PDF dosyası fiziksel olarak bulunamadı.');
            $this->line('Aranan yol: ' . $pdfPath);

            return Command::FAILURE;
        }

        $this->info('PDF dosyası bulundu.');

        // PDF metnini çıkar
        $this->info('PDF metni çıkarılıyor...');

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            $this->error('PDF okunamadı.');
            $this->line($e->getMessage());

            return Command::FAILURE;
        }

        // Geçersiz UTF-8 karakterlerini temizle
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        if (empty(trim($text))) {
            $this->error('PDF metni boş. OCR gerekebilir.');

            return Command::FAILURE;
        }

        $this->info('PDF metni başarıyla çıkarıldı.');
        $this->line('Metin uzunluğu: ' . strlen($text) . ' karakter');

        // Test sorusu
        $question = 'Bu PDF’nin konusu nedir?';

        $this->newLine();
        $this->info('Soru: ' . $question);
        $this->info('Groq cevap üretiyor...');

        // Şimdilik test için PDF'nin ilk 12000 karakteri
        $pdfContext = mb_substr($text, 0, 12000);

        $prompt = <<<PROMPT
Sen bir PDF soru-cevap asistanısın.

Aşağıdaki PDF içeriğini kullanarak soruyu cevapla.

Kurallar:
- Sadece verilen PDF içeriğine dayan.
- PDF'de bulunmayan bilgileri uydurma.
- Türkçe cevap ver.
- Cevabı açık ve anlaşılır yaz.

PDF İÇERİĞİ:
{$pdfContext}

SORU:
{$question}
PROMPT;

        try {
            $response = Http::timeout(60)
                ->withToken(env('GROQ_API_KEY'))
                ->post(
                    'https://api.groq.com/openai/v1/chat/completions',
                    [
                        'model' => 'openai/gpt-oss-20b',

                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => $prompt,
                            ],
                        ],

                        'temperature' => 0.2,
                    ]
                );
        } catch (\Throwable $e) {
            $this->error('Groq bağlantı hatası!');
            $this->line($e->getMessage());

            return Command::FAILURE;
        }

        if (!$response->successful()) {
            $this->error('Groq API hatası!');
            $this->line($response->body());

            return Command::FAILURE;
        }

        $answer = $response->json('choices.0.message.content');

        $this->newLine();
        $this->info('Groq PDF testi başarılı!');

        $this->newLine();

        $this->line('========================================');
        $this->line('PDF');
        $this->line($pdfKayit->dosya_adi);

        $this->line('========================================');
        $this->line('SORU');
        $this->line($question);

        $this->line('========================================');
        $this->line('AI CEVABI');
        $this->line($answer);

        $this->line('========================================');

        return Command::SUCCESS;
    }
}