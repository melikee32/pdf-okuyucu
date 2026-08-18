
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>📚 PDF Okuyucu</title>

    @vite('resources/css/pdf.css')
</head>


<body>

<div class="container">


    <!-- =========================================================
         NAVBAR
         ========================================================= -->

    <nav class="navbar">

        <span class="navbar-brand">
            📚 PDF Okuyucu
        </span>

        <div class="navbar-links">

            <a href="/">
                ⌂ Ana Sayfa
            </a>

            <a href="/gecmis">
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


    <!-- =========================================================
         BAŞLIK
         ========================================================= -->

    <div class="header">

        <h1>
            📚 PDF Okuyucu
        </h1>

        <p>
            PDF dosyalarınızı yükleyin ve içerikleri hakkında sorular sorun
        </p>

    </div>


    <!-- =========================================================
         FLASH MESAJI
         ========================================================= -->

    @if(session('cevap'))

        <div class="{{ session('hata') ? 'alert-error' : 'alert-info' }} pdf-feedback">

            <span class="feedback-icon">
                {{ session('hata') ? '!' : '✓' }}
            </span>

            <p>
                {{ session('cevap') }}
            </p>

        </div>

    @endif


    <!-- =========================================================
         AKTİF DOKÜMANLAR
         ========================================================= -->

    @if(session('pdfler') && count(session('pdfler')) > 0)

        <div class="active-docs-wrapper">


            <!-- =================================================
                 AKTİF DOKÜMAN BAŞLIĞI
                 ================================================= -->

            <div class="active-docs-header">

                <div class="active-docs-title">

                    <span class="active-docs-title-icon">
                        📚
                    </span>

                    <span>
                        Aktif Dokümanlar
                    </span>

                    <span class="active-docs-count">
                        {{ count(session('pdfler')) }} PDF
                    </span>

                </div>

            </div>


            <!-- =================================================
                 PDF KARTLARI
                 ================================================= -->

            <div class="active-docs-list">

                @foreach(session('pdfler') as $pdf)

                    <div class="active-doc-card">


                        <!-- PDF'İ GÖRÜNTÜLE (İKON + BİLGİ) -->

                        <a
                            href="{{ url('/pdf/' . $pdf['id']) }}"
                            target="_blank"
                            rel="noopener"
                            class="active-doc-link"
                            title="PDF'i görüntüle"
                        >

                            <div class="active-doc-icon">
                                📄
                            </div>

                            <div class="active-doc-info">

                                <span
                                    class="active-doc-name"
                                    title="{{ $pdf['adi'] }}"
                                >
                                    {{ $pdf['adi'] }}
                                </span>

                                <span class="active-doc-status">
                                    Soru-cevap için aktif
                                </span>

                            </div>

                        </a>


                        <!-- KART AKSİYONLARI -->

                        <div class="active-doc-card-actions">

                            <a
                                href="{{ url('/pdf/' . $pdf['id']) }}"
                                target="_blank"
                                rel="noopener"
                                class="active-doc-view"
                                title="PDF'i görüntüle"
                            >
                                👁
                            </a>

                            <form
                                action="{{ url('/pdf-kaldir/' . $pdf['id']) }}"
                                method="POST"
                                class="remove-pdf-form"
                                onsubmit="return confirm('Bu PDF aktif dokümanlardan kaldırılsın mı?');"
                            >

                                @csrf
                                @method('DELETE')

                                <button
                                    type="submit"
                                    class="active-doc-remove"
                                    title="Aktif dokümanlardan kaldır"
                                >
                                    ×
                                </button>

                            </form>

                        </div>

                    </div>

                @endforeach

            </div>


            <!-- =================================================
                 AKSİYON BUTONLARI
                 ================================================= -->

            <div class="active-doc-actions">


                <!-- =================================================
                     PDF EKLE
                     ================================================= -->

                <form
                    action="{{ url('/pdf-yukle') }}"
                    method="POST"
                    enctype="multipart/form-data"
                    id="addPdfForm"
                >

                    @csrf

                    <input
                        type="file"
                        name="pdf[]"
                        id="addPdfInput"
                        accept=".pdf,application/pdf"
                        multiple
                        hidden
                    >

                    <button
                        type="button"
                        class="btn-add-pdf"
                        id="addPdfButton"
                    >
                        ＋ PDF Ekle
                    </button>

                </form>


                <!-- =================================================
                     DEĞİŞTİR
                     ================================================= -->

                <a
                    href="{{ url('/yeni-pdf') }}"
                    class="btn-change-pdf"
                >
                    ↻ Değiştir
                </a>

            </div>

        </div>

    @endif


    <!-- =========================================================
         PDF YÜKLENMEDİYSE
         ========================================================= -->

    @if(!session('pdfler') || count(session('pdfler')) === 0)

        <div class="card upload-card">

            <div class="upload-area">


                <div class="upload-title-row">

                    <span class="upload-icon">
                        📄
                    </span>

                    <h2>
                        PDF Yükle
                    </h2>

                </div>


                <p class="upload-description">
                    Analiz etmek istediğiniz PDF dosyalarını seçiniz
                    (birden fazla seçebilirsiniz).
                </p>


                <form
                    id="uploadForm"
                    action="/pdf-yukle"
                    method="POST"
                    enctype="multipart/form-data"
                >

                    @csrf


                    <!-- GİZLİ INPUT -->

                    <input
                        id="pdfInput"
                        type="file"
                        name="pdf[]"
                        accept=".pdf,application/pdf"
                        multiple
                        required
                        hidden
                    >


                    <div class="upload-controls">


                        <!-- DOSYA SEÇ -->

                        <label
                            for="pdfInput"
                            class="file-select-button"
                        >
                            📁 Dosyaları Seç
                        </label>


                        <!-- DOSYA ADI -->

                        <span
                            id="fileName"
                            class="file-name"
                        >
                            Henüz dosya seçilmedi
                        </span>


                        <!-- YÜKLE -->

                        <button
                            id="uploadButton"
                            type="submit"
                            class="upload-button"
                        >
                            ➤ PDF'LERİ YÜKLE
                        </button>

                    </div>


                    <p
                        id="uploadMessage"
                        class="upload-message"
                    ></p>

                </form>

            </div>

        </div>


    <!-- =========================================================
         PDF YÜKLENDİYSE
         ========================================================= -->

    @else

        <div class="pdf-layout">


            <!-- =================================================
                 SORU SOR
                 ================================================= -->

            <div class="card question-area">

                <h2>
                    💬 Yüklü PDF'ler Hakkında Soru Sor
                </h2>


                <form
                    id="soruForm"
                    action="/soru"
                    method="POST"
                >

                    @csrf


                    <!-- SORU -->

                    <div class="form-group">

                        <label class="form-label">
                            Sorunuzu yazınız
                        </label>

                        <textarea
                            name="soru"
                            placeholder="ᯓ➤ Sorunuzu yazınız..."
                            required
                        ></textarea>

                    </div>


                    <!-- =================================================
                         AI MODELİ
                         ================================================= -->

                    <div class="form-group model-group">

                        <label class="form-label">
                            AI Modeli
                        </label>


                        <div class="model-switcher">


                            <!-- GEMINI -->

                            <label class="model-option">

                                <input
                                    type="radio"
                                    name="model"
                                    value="gemini"
                                    checked
                                >

                                <span class="model-pill">
                                    ✨ Gemini
                                </span>

                            </label>


                            <!-- GROK -->

                            <label class="model-option">

                                <input
                                    type="radio"
                                    name="model"
                                    value="grok"
                                >

                                <span class="model-pill">
                                    ⚡ Grok
                                </span>

                            </label>

                        </div>

                    </div>


                    <!-- SOR -->

                    <button
                        type="submit"
                        class="question-button"
                    >
                        🤖 SOR
                    </button>

                </form>

            </div>


            <!-- =================================================
                 AI CEVABI
                 ================================================= -->

            <div class="card ai-card">


                <div class="ai-card-header">

                    <h2>
                        🤖 AI Cevabı
                    </h2>

                    @if(!empty($kullanilanAi))

                        <span class="ai-used-badge">
                            {{ $kullanilanAi }}
                        </span>

                    @endif

                </div>


                <!-- SORU -->

                <div class="question-box">

                    <span class="pill-title">
                        SORUNUZ
                    </span>

                    <p>
                        ᯓ➤ {{ $question ?? '' }}
                    </p>

                </div>


                <!-- CEVAP -->

                <div class="answer-box">

                    <span class="pill-title-green">
                        CEVAP
                    </span>


                    @if(isset($answer) && $answer)

                        <div class="ai-answer-text">
                            {{ $answer }}
                        </div>

                    @else

                        <p class="empty-answer">
                            AI cevabı burada görünecek...
                        </p>

                    @endif

                </div>

            </div>

        </div>

    @endif

</div>


<!-- =============================================================
     SORU YÜKLENİYOR OVERLAY'İ
     ============================================================= -->

<div id="soruLoadingOverlay" class="soru-loading-overlay">

    <div class="soru-loading-box">

        <div class="soru-loading-spinner"></div>

        <p id="soruLoadingText" class="soru-loading-text">
            Sorunuz analiz ediliyor...
        </p>

        <div class="soru-loading-bar-track">
            <div id="soruLoadingBar" class="soru-loading-bar-fill"></div>
        </div>

        <p class="soru-loading-hint">
            PDF sayısına ve seçili AI modeline göre bu birkaç saniye
            ile bir dakika arasında sürebilir.
        </p>

    </div>

</div>


<!-- =============================================================
     JAVASCRIPT
     ============================================================= -->

<script>



    /* =========================================================
       ANA PDF YÜKLEME ALANI
       ========================================================= */

    const pdfInput = document.getElementById("pdfInput");
    const uploadButton = document.getElementById("uploadButton");
    const uploadMessage = document.getElementById("uploadMessage");
    const fileName = document.getElementById("fileName");


    if (pdfInput) {

        pdfInput.addEventListener("change", function () {

            if (pdfInput.files.length > 0) {

                const sayi = pdfInput.files.length;

                const dosyalar = Array
                    .from(pdfInput.files)
                    .map(file => file.name);


                if (fileName) {

                    if (sayi === 1) {

                        fileName.textContent = dosyalar[0];

                    } else {

                        fileName.textContent =
                            sayi + " PDF seçildi";

                    }

                    fileName.classList.add("selected");

                }


                if (uploadMessage) {

                    uploadMessage.classList.remove("error");

                    uploadMessage.classList.add("success");

                    uploadMessage.textContent =
                        sayi > 1
                            ? sayi + " PDF seçildi. Yüklemeye hazırsınız."
                            : "PDF seçildi. Yüklemeye hazırsınız.";

                }


                if (uploadButton) {

                    uploadButton.classList.remove("error");

                    uploadButton.classList.add("ready");

                }

            }

        });

    }


    /* =========================================================
       ANA PDF YÜKLEME KONTROLÜ
       ========================================================= */

    if (uploadButton) {

        uploadButton.addEventListener("click", function (event) {

            if (!pdfInput || pdfInput.files.length === 0) {

                event.preventDefault();

                uploadButton.classList.remove("ready");

                uploadButton.classList.add("error");


                if (uploadMessage) {

                    uploadMessage.classList.remove("success");

                    uploadMessage.classList.add("error");

                    uploadMessage.textContent =
                        "Lütfen önce en az bir PDF dosyası seçin.";

                }

            }

        });

    }


    /* =========================================================
       AKTİF ALANDAN PDF EKLE
       ========================================================= */

    const addPdfInput = document.getElementById("addPdfInput");
    const addPdfForm = document.getElementById("addPdfForm");
    const addPdfButton = document.getElementById("addPdfButton");


    if (addPdfButton && addPdfInput) {

        addPdfButton.addEventListener("click", function () {

            addPdfInput.click();

        });

    }


    if (addPdfInput && addPdfForm) {

        addPdfInput.addEventListener("change", function () {

            if (addPdfInput.files.length > 0) {

                addPdfForm.submit();

            }

        });

    }


    /* =========================================================
       SORU GÖNDERİLİRKEN YÜKLEME EKRANI
       ========================================================= */

    const soruForm = document.getElementById("soruForm");
    const soruLoadingOverlay = document.getElementById("soruLoadingOverlay");
    const soruLoadingBar = document.getElementById("soruLoadingBar");
    const soruLoadingText = document.getElementById("soruLoadingText");


    if (soruForm && soruLoadingOverlay) {

        soruForm.addEventListener("submit", function (event) {

            const asamalar = [
                "Sorunuz analiz ediliyor...",
                "PDF'lerde ilgili bölümler aranıyor...",
                "En alakalı sayfalar seçiliyor...",
                "AI cevabı hazırlıyor...",
                "Neredeyse hazır...",
            ];

            let asamaIndex = 0;

            soruLoadingText.textContent = asamalar[0];

            soruLoadingOverlay.classList.add("visible");


            let yuzde = 0;

            const ilerlemeTimer = setInterval(function () {

                const kalan = 90 - yuzde;

                const artis = Math.max(0.4, kalan * 0.045);

                yuzde = Math.min(90, yuzde + artis);

                soruLoadingBar.style.width = yuzde + "%";

            }, 220);


            const mesajTimer = setInterval(function () {

                asamaIndex = Math.min(
                    asamaIndex + 1,
                    asamalar.length - 1
                );

                soruLoadingText.textContent = asamalar[asamaIndex];

            }, 3200);


            soruForm.dataset.timers = "active";

        });

    }


</script>


</body>
</html>
