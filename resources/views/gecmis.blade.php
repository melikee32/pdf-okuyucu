<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geçmiş — PDF Okuyucu</title>
    @vite('resources/css/pdf.css')
</head>
<body>

<div class="container">

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="navbar-brand">📚 PDF Okuyucu</span>
        <div class="navbar-links">
            <a href="/">Ana Sayfa</a>
            <a href="/gecmis" class="active">Geçmiş</a>
            <form action="/logout" method="POST" style="display:inline;">
                @csrf
                <button type="submit" class="logout-btn">Çıkış</button>
            </form>
        </div>
    </nav>


    <div class="header" style="margin-top:20px;">
        <h1>🕓 Soru Geçmişi</h1>
        <p>Daha önce sorduğunuz sorular ve AI cevapları</p>
    </div>


    @if($sorular->isEmpty())

    <div class="card" style="text-align:center; padding:40px;">
        <p style="color:#7A8494;">Henüz soru sormadınız.</p>
        <a href="/" style="display:inline-block; margin-top:16px; color:#687F96; font-weight:600; text-decoration:none;">
            ← PDF Yükle ve Soru Sor
        </a>
    </div>

    @else

    @foreach($sorular as $soru)

    <div class="card" style="margin-bottom:16px;">

        <!-- PDF adı ve tarih -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">

            <span style="font-size:0.8rem; color:#7A8494; background:rgba(255,255,255,0.3); padding:3px 10px; border-radius:20px;">
                📄 {{ $soru->pdf->dosya_adi ?? '—' }}
            </span>

            <span style="font-size:0.8rem; color:#9CA3AF;">
                {{ $soru->created_at->format('d.m.Y H:i') }}
            </span>

        </div>

        <!-- Soru -->
        <div class="question-box">
            <strong>Sorunuz</strong>
            <p>{{ $soru->soru }}</p>
        </div>

        <!-- Cevap -->
        <div class="answer-box">
            <strong>Cevap</strong>
            <div class="ai-answer">{{ $soru->cevap }}</div>
        </div>

    </div>

    @endforeach


    <!-- Sayfalama -->
    <div style="margin-top:10px;">
        {{ $sorular->links() }}
    </div>

    @endif

</div>

</body>
</html>