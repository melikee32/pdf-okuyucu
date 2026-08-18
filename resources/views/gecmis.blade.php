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

        <!-- =====================================================
             NAVBAR
             ===================================================== -->
        <nav class="navbar">

            <span class="navbar-brand">
                📚 PDF Okuyucu
            </span>

            <div class="navbar-links">

                <a href="/">
                   ⌂ Ana Sayfa
                </a>

                <a href="/gecmis" class="active">
                   ↻ Geçmiş
                </a>

                <form action="/logout" method="POST" style="display:inline;">
                    @csrf

                    <button type="submit" class="logout-btn">
                        ⇾Çıkış
                    </button>
                </form>

            </div>

        </nav>


        <!-- =====================================================
             SAYFA BAŞLIĞI
             ===================================================== -->
        <div class="header" style="margin-top:10px;">

            <h1>
                🕓 Soru Geçmişi
            </h1>

            <p>
                Daha önce sorduğunuz sorular ve AI cevapları
            </p>

        </div>


        <!-- =====================================================
             GEÇMİŞ BOŞSA
             ===================================================== -->
        @if($sorular->isEmpty())

        <div class="card" style="text-align:center; padding:40px;">

            <p style="color:#7A8494; margin-bottom:16px;">
                Henüz soru sormadınız.
            </p>

            <a href="/" style="color:#687F96; font-weight:600; text-decoration:none;">
                ← PDF Yükle ve Soru Sor
            </a>

        </div>

        @else


        <!-- =================================================
                 GÜNLERE GÖRE SORULAR
                 ================================================= -->
        @foreach($sorular as $tarih => $gunSorular)

        <!-- HER GÜNÜN KENDİ GRUBU -->
        <div class="gun-grup">


            <!-- =================================================
                         GÜN BAŞLIĞI
                         NOT: "kapali" class'ı başta ekli — sayfa ilk
                         açıldığında günler kapalı gelsin diye. JS
                         (toggleGun) tıklayınca bu class'ı ekleyip
                         çıkararak açıp kapatıyor.
                         ================================================= -->
            <div class="gun-baslik kapali" onclick="toggleGun(this)">

                <span class="gun-label">
                    📅 {{ $tarih }}
                </span>

                <div class="gun-cizgi"></div>

                <span class="gun-sayi">
                    {{ $gunSorular->count() }} soru
                </span>

                <span class="gun-ok">
                    ▼
                </span>

            </div>


            <!-- =================================================
                         O GÜNE AİT SORULAR
                         Aynı sebeple burada da başta "kapali" var.
                         ================================================= -->
            <div class="gun-sorular kapali">

                @foreach($gunSorular as $soru)

                <!-- SORU KARTI -->
                <div class="gecmis-kart"
                    onclick="toggleCevap({{ $soru->id }})">


                    <!-- PDF ADI + SAAT -->
                    <div class="gecmis-kart-ust">

                        <span class="gecmis-pdf-adi">
                            📄 {{ $soru->pdf->dosya_adi ?? '—' }}
                        </span>

                        <span class="gecmis-saat">
                            {{ $soru->created_at->format('H:i') }}
                        </span>

                        <!-- SİL -->
                        <form
                            action="{{ url('/soru-sil/' . $soru->id) }}"
                            method="POST"
                            class="soru-sil-form"
                            onclick="event.stopPropagation();"
                            onsubmit="return confirm('Bu soru geçmişten silinsin mi?');">

                            @csrf
                            @method('DELETE')

                            <button
                                type="submit"
                                class="soru-sil-btn"
                                title="Bu soruyu sil">
                                🗑
                            </button>

                        </form>

                    </div>


                    <!-- SORU -->
                    <p class="gecmis-soru">
                        {{ $soru->soru }}
                    </p>


                    <!-- CEVAP TOGGLE -->
                    <div class="gecmis-toggle"
                        id="toggle-{{ $soru->id }}">

                        <span>
                            ▼ Cevabı gör
                        </span>

                    </div>


                    <!-- CEVAP -->
                    <div class="gecmis-cevap"
                        id="cevap-{{ $soru->id }}">

                        <div class="gecmis-cevap-ic">
                            {{ $soru->cevap }}
                        </div>

                    </div>

                </div>

                @endforeach

            </div>

        </div>

        @endforeach

        @endif

    </div>


    <!-- =========================================================
         JAVASCRIPT
         ========================================================= -->

    <script>
        /* =========================================================
           SORU CEVABINI AÇ / KAPAT
           ========================================================= */
        function toggleCevap(id) {

            const cevap = document.getElementById('cevap-' + id);
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


        /* =========================================================
           GÜNÜ AÇ / KAPAT
           ========================================================= */
        function toggleGun(baslik) {

            // Sadece tıklanan günün başlığını değiştir
            baslik.classList.toggle('kapali');


            // Tıklanan başlığın bulunduğu gün grubunu bul
            const grup = baslik.closest('.gun-grup');

            if (!grup) {
                return;
            }


            // SADECE BU GÜNE AİT soruları bul
            const sorular = grup.querySelector('.gun-sorular');

            if (!sorular) {
                return;
            }


            // Bu günün sorularını aç / kapat
            sorular.classList.toggle('kapali');

        }
    </script>

</body>

</html>