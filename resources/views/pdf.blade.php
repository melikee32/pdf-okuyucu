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

        <!-- BAŞLIK -->
        <div class="header">
            <h1>📚 PDF Okuyucu</h1>
            <p>PDF dosyanızı yükleyin ve içeriği hakkında sorular sorun</p>
        </div>


        <!-- AKTİF DOKÜMAN BARI -->
        <!-- Sadece PDF yüklüyse görünür -->
        @if(session('pdf_adi'))

        <div class="active-doc-bar">

            <span class="dot"></span>

            <span>
                Aktif Doküman: {{ session('pdf_adi') }}
            </span>

            <a href="/yeni-pdf">
                Değiştir ›
            </a>

        </div>

        @endif


        <!-- FLASH MESAJI -->
        @if(session('cevap'))

        <div class="{{ session('hata') ? 'alert-error' : 'alert-info' }}">
            <p>{{ session('cevap') }}</p>
        </div>

        @endif



        {{-- =====================================================
         PDF YÜKLENMEDİYSE
         SADECE PDF YÜKLEME ALANI GÖRÜNECEK
         ===================================================== --}}

        @if(!session('pdf_adi'))

        <div class="card">

            <div class="upload-area">

                <h2>📄 PDF Yükle</h2>

                <p>
                    Analiz etmek istediğiniz PDF dosyasını seçiniz.
                </p>


                <form
                    id="uploadForm"
                    action="/pdf-yukle"
                    method="POST"
                    enctype="multipart/form-data">

                    @csrf


                    <input
                        id="pdfInput"
                        type="file"
                        name="pdf"
                        accept=".pdf"
                        required>


                    <button
                        id="uploadButton"
                        type="submit"
                        class="upload-button">

                        ➤ PDF YÜKLE

                    </button>


                    <p
                        id="uploadMessage"
                        class="upload-message">
                    </p>

                </form>

            </div>

        </div>



        {{-- =====================================================
         PDF YÜKLENDİYSE
         SOLDA PDF + SAĞDA SORU/AI
         ===================================================== --}}

        @else

        <div class="pdf-layout">




            <!-- SAĞ TARAF -->
            <div class="pdf-main">


                <!-- SORU SOR -->
                <div class="card question-area">

                    <h2>
                        💬 PDF Hakkında Soru Sor
                    </h2>


                    <form
                        action="/soru"
                        method="POST">

                        @csrf


                        <textarea
                            name="soru"
                            placeholder="PDF hakkında sorunuzu yazınız..."
                            required></textarea>


                        <button
                            type="submit"
                            class="question-button">

                            SOR

                        </button>

                    </form>

                </div>



                <!-- AI CEVABI -->
                <div class="card">

                    <h2>
                        🤖 AI Cevabı
                    </h2>


                    <div class="question-box">

                        <strong>Sorunuz</strong>

                        <p>
                            {{ $question ?? '' }}
                        </p>

                    </div>


                    <div class="answer-box">

                        <strong>Cevap</strong>


                        @if(isset($answer) && $answer)

                        <div class="ai-answer">
                            {{ $answer }}
                        </div>

                        @else

                        <p>
                            AI cevabı burada görünecek...
                        </p>

                        @endif

                    </div>

                </div>


            </div>

        </div>

        @endif

    </div>



    <!-- ============================================================
     JAVASCRIPT
     ============================================================ -->

    <script>
        const pdfInput = document.getElementById("pdfInput");
        const uploadButton = document.getElementById("uploadButton");
        const uploadMessage = document.getElementById("uploadMessage");


        // PDF yükleme alanı sayfada varsa çalıştır
        if (pdfInput && uploadButton && uploadMessage) {


            // Kullanıcı PDF seçtiğinde
            pdfInput.addEventListener("change", function() {

                if (pdfInput.files.length > 0) {

                    uploadButton.classList.remove("error");

                    uploadButton.classList.add("ready");

                    uploadMessage.classList.remove("error");

                    uploadMessage.classList.add("success");

                    uploadMessage.textContent =
                        "PDF seçildi. Yüklemeye hazırsınız.";

                }

            });


            // Dosya seçmeden yüklemeye basınca
            uploadButton.addEventListener("click", function(event) {

                if (pdfInput.files.length === 0) {

                    event.preventDefault();

                    uploadButton.classList.remove("ready");

                    uploadButton.classList.add("error");

                    uploadMessage.classList.remove("success");

                    uploadMessage.classList.add("error");

                    uploadMessage.textContent =
                        "Lütfen önce bir PDF dosyası seçin.";

                }

            });

        }
    </script>


</body>

</html>