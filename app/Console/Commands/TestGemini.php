<?php

namespace App\Console\Commands;

use App\Models\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser;

class TestGemini extends Command
{
    protected $signature = 'test:gemini';

    protected $description = 'Test Gemini API with a registered PDF';

    public function handle()
    {
        $this->info('PDF kaydı aranıyor...');

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

        $pdfPath = storage_path('app/private/' . $pdfKayit->yol);

        if (!file_exists($pdfPath)) {
            $this->error('PDF dosyası bulunamadı.');
            $this->line('Aranan yol: ' . $pdfPath);

            return Command::FAILURE;
        }

        $this->info('PDF dosyası bulundu.');
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

        // UTF-8 temizliği
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        if (empty(trim($text))) {
            $this->error('PDF metni boş.');

            return Command::FAILURE;
        }

        $this->info('PDF metni başarıyla çıkarıldı.');
        $this->line('Metin uzunluğu: ' . strlen($text) . ' karakter');

        // Groq ile aynı soru
        $question = 'Bu PDF’nin konusu nedir?';

        $this->newLine();
        $this->info('Soru: ' . $question);
        $this->info('Gemini cevap üretiyor...');

        // Groq testindekiyle aynı miktarda içerik
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

        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey) {
            $this->error('GEMINI_API_KEY bulunamadı.');

            return Command::FAILURE;
        }

        try {
            $response = Http::timeout(60)
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $apiKey,
                    [
                        'contents' => [
                            [
                                'parts' => [
                                    [
                                        'text' => $prompt,
                                    ],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2,
                        ],
                    ]
                );
        } catch (\Throwable $e) {
            $this->error('Gemini bağlantı hatası!');
            $this->line($e->getMessage());

            return Command::FAILURE;
        }

        if (!$response->successful()) {
            $this->error('Gemini API hatası!');
            $this->line($response->body());

            return Command::FAILURE;
        }

        $answer = $response->json('candidates.0.content.parts.0.text');

        if (!$answer) {
            $this->error('Gemini cevap döndürmedi.');
            $this->line($response->body());

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Gemini PDF testi başarılı!');

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