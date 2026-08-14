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

    <div class="header" style="margin-top:10px;">
        <h1>🕓 Soru Geçmişi</h1>
        <p>Daha önce sorduğunuz sorular ve AI cevapları</p>
    </div>


    @if($sorular->isEmpty())

    <div class="card" style="text-align:center; padding:40px;">
        <p style="color:#7A8494; margin-bottom:16px;">Henüz soru sormadınız.</p>
        <a href="/" style="color:#687F96; font-weight:600; text-decoration:none;">← PDF Yükle ve Soru Sor</a>
    </div>

    @else

    @foreach($sorular as $tarih => $gunSorular)

    <!-- GÜN BAŞLIĞI -->
    <div class="gun-baslik">
        <span class="gun-label">📅 {{ $tarih }}</span>
        <div class="gun-cizgi"></div>
        <span class="gun-sayi">{{ $gunSorular->count() }} soru</span>
    </div>

    <!-- O GÜNE AİT SORULAR -->
    @foreach($gunSorular as $i => $soru)

    <div class="gecmis-kart" onclick="toggleCevap({{ $soru->id }})">

        <!-- ÜST SATIR: pdf adı + saat -->
        <div class="gecmis-kart-ust">
            <span class="gecmis-pdf-adi">📄 {{ $soru->pdf->dosya_adi ?? '—' }}</span>
            <span class="gecmis-saat">{{ $soru->created_at->format('H:i') }}</span>
        </div>

        <!-- SORU METNİ -->
        <p class="gecmis-soru">{{ $soru->soru }}</p>

        <!-- AÇMA BUTONU -->
        <div class="gecmis-toggle" id="toggle-{{ $soru->id }}">
            <span>▼ Cevabı gör</span>
        </div>

        <!-- CEVAP (gizli, tıklayınca açılır) -->
        <div class="gecmis-cevap" id="cevap-{{ $soru->id }}">
            <div class="gecmis-cevap-ic">{{ $soru->cevap }}</div>
        </div>

    </div>

    @endforeach

    @endforeach

    @endif

</div>


<script>
    function toggleCevap(id) {
        const cevap  = document.getElementById('cevap-' + id);
        const toggle = document.getElementById('toggle-' + id);

        const acik = cevap.classList.contains('acik');

        if (acik) {
            cevap.classList.remove('acik');
            toggle.innerHTML = '<span>▼ Cevabı gör</span>';
        } else {
            cevap.classList.add('acik');
            toggle.innerHTML = '<span>▲ Kapat</span>';
        }
    }
</script>

</body>
</html>