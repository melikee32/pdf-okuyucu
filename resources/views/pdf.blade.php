<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title> 📚 PDF Okuyucu </title>

    @vite('resources/css/pdf.css')

</head>

<body>

    <div class="container">

        <div class="header">

            <h1>📚 PDF Okuyucu</h1>

            <p>PDF dosyanızı yükleyin ve içeriği hakkında sorular sorun</p>

        </div>



        <!-- PDF YÜKLEME -->

        <div class="card">
            <div class="upload-area">

                <h2>📄 PDF Yükle</h2>
                <br>
                <p>Analiz etmek istediğiniz PDF dosyasını seçiniz.</p>



                <form id="uploadForm" action="/pdf-yukle" method="POST" enctype="multipart/form-data">

                    @csrf

                    <input id="pdfInput" type="file" name="pdf" accept=".pdf">

                    <br><br>

                    <button id="uploadButton" class="upload-button" type="submit"> PDF YÜKLE </button>

                    <p id="uploadMessage" class="upload-message"></p>

                </form>
            </div>
        </div>



        <!-- PDF YÜKLENDİYSE -->

        @if(session('pdf_adi'))

        <div class="card">

            <h2>📄 Yüklenen PDF</h2>

            <div class="pdf-file">

                <div class="pdf-info">
                    <span>📄</span>

                    <span class="upload-text">
                        {{ session('pdf_adi') }}
                    </span>
                </div>

                <a href="/yeni-pdf" class="clear-pdf">
                    ✕ Temizle
                </a>

            </div>

        </div>






        <!-- SORU SORMA -->

        <div class="card question-area">
            <h2>💬 PDF Hakkında Soru Sor</h2>

            <form action="/soru" method="POST">

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



        <div class="card">

            <h2>🤖 AI Cevabı</h2>

            <div>

                <div class="question-box">
                    <strong>💬 Sorunuz:</strong>
                    <p>{{ $question ?? '' }}</p>
                </div>

                <br>
                @if(session('answer'))

                <p> {{ session('answer') }}</p>

                @else

                <p>
                    AI cevabı burada görünecek...
                </p>

                @endif
            </div>

        </div>


        @endif


    </div>



    <script>
        //<input id="pdfInput"> elementini JavaScript'e tanıtıyor.

        const pdfInput = document.getElementById("pdfInput");
        const uploadButton = document.getElementById("uploadButton");
        const uploadMessage = document.getElementById("uploadMessage");


        // Kullanıcı PDF seçtiğinde . //* change = kullanıcı dosya seçtiğinde çalış.

        pdfInput.addEventListener("change", function() {

            if (pdfInput.files.length > 0) {

                uploadButton.classList.remove("error");
                uploadButton.classList.add("ready"); //butona ready class'ı ekliyor. yeşil 

                uploadMessage.classList.remove("error");
                uploadMessage.classList.add("success");

                uploadMessage.textContent =
                    "PDF seçildi. Yüklemeye hazırsınız.";

            }

        });


        // Kullanıcı dosya seçmeden PDF YÜKLE butonuna bastığında

        uploadButton.addEventListener("click", function(event) {

            if (pdfInput.files.length === 0) {

                event.preventDefault(); //formun gönderilmesini engelliyor.

                uploadButton.classList.remove("ready");
                uploadButton.classList.add("error"); //* butona error class'ı ekliyor. kırmızı

                uploadMessage.classList.remove("success");
                uploadMessage.classList.add("error");

                uploadMessage.textContent =
                    "Lütfen önce bir PDF dosyası seçin.";

            }

        });
    </script>






</body>

</html>