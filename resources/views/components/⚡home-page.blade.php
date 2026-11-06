<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Native\Mobile\Facades\Camera;
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\Camera\PhotoTaken;

new class extends Component {
    public string $code = '';
    public string $ussdPrefix = '*321*';
    public string $photo = '';
    public bool $processing = false;

    // Ouvrir l'appareil photo
    public function takePhoto()
    {
        Camera::getPhoto();
    }

    // Récupérer la photo prise avec NativePHP
    #[OnNative(PhotoTaken::class)]
    public function photoTaken(string $path)
    {
        if (!is_file($path)) {
            $this->dispatch('ocr-error', message: 'Photo introuvable.');
            return;
        }

        $image = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($path));

        $this->photo = $image;
        $this->code = '';

        // Envoyer l'image au navigateur pour Tesseract.js
        $this->dispatch('photo-ready', image: $image);

        // Nettoyer la photo temporaire
        @unlink($path);
    }

    // Recevoir le résultat OCR
    public function setCode(string $code)
    {
        $this->code = preg_replace('/\D/', '', $code);
    }

    // Réinitialiser
    public function resetScanner()
    {
        $this->code = '';
        $this->photo = '';
        $this->processing = false;
    }
};
?>

<div class="container py-4 main-container">

    <div class="text-center mb-4">
        <h3 class="fw-bold">
            <i class="bi bi-lightning-charge-fill text-warning"></i>
            FlashRecharge
        </h3>
        <p class="text-secondary">
            Recharge tes crédits en quelques secondes.
        </p>
    </div>

    {{-- APPAREIL PHOTO --}}
    {{-- APPAREIL PHOTO AVEC CADRE DE VISÉE --}}
    <div class="card bg-dark border-secondary mb-3">
        <div class="card-body text-center p-4">

            @if ($photo)
                <img src="{{ $photo }}" class="img-fluid rounded mb-3" style="max-height: 220px;"
                    alt="Carte photographiée">
            @else
                <!-- Conteneur avec un viseur visuel pour guider l'utilisateur -->
                <div class="scanner-viewport position-relative rounded overflow-hidden mb-3 border border-2 border-dashed border-warning d-flex align-items-center justify-content-center"
                    style="height: 150px; background-color: rgba(0,0,0,0.3);">
                    <div class="text-center p-2">
                        <i class="bi bi-upc-scan fs-2 text-warning"></i>
                        <p class="text-light small mb-0 mt-1">Centrez les chiffres ici</p>
                    </div>
                    <!-- Ligne de visée centrale horizontale -->
                    <div class="position-absolute w-100 border-top border-warning opacity-50"></div>
                </div>
            @endif

            <button type="button" wire:click="takePhoto" class="btn btn-primary w-100 mt-2">
                <i class="bi bi-camera-fill me-2"></i>
                Photographier la carte
            </button>

        </div>
    </div>

    {{-- RECONNAISSANCE --}}
    <div class="card bg-dark border-secondary mb-3">
        <div class="card-body">

            <h6 class="fw-bold mb-3">
                <i class="bi bi-upc-scan me-2"></i>
                Séquence détectée
            </h6>

            <input type="text" wire:model.live="code" class="form-control form-control-lg text-center fw-bold"
                placeholder="Les chiffres apparaîtront ici" inputmode="numeric" autocomplete="off">

            <div class="small text-secondary mt-2">
                Vérifie les chiffres avant de continuer.
            </div>

        </div>
    </div>

    {{-- PARAMÈTRES USSD --}}
    <div class="card bg-dark border-secondary mb-3">
        <div class="card-body">

            <label class="form-label fw-bold">
                Préfixe USSD
            </label>

            <input type="text" wire:model.live="ussdPrefix" class="form-control" placeholder="*123*">

            <small class="text-secondary">
                Exemple : *123*CODE#
            </small>

        </div>
    </div>

    {{-- COMPOSITION --}}
    <div class="card bg-dark border-secondary">
        <div class="card-body">

            <h6 class="fw-bold">
                <i class="bi bi-telephone-fill me-2"></i>
                Recharge
            </h6>

            <div class="text-center py-3">
                <h4 class="fw-bold text-success">
                    {{ $ussdPrefix }}{{ $code }}#
                </h4>
            </div>

            @if (strlen($code) > 0 && strlen($ussdPrefix) > 0)
                <a href="tel:{{ $ussdPrefix }}{{ $code }}%23" class="btn btn-success w-100">
                    <i class="bi bi-telephone-fill me-2"></i>
                    Composer l'USSD
                </a>
            @else
                <button class="btn btn-secondary w-100" disabled>
                    En attente du code
                </button>
            @endif

            <button type="button" wire:click="resetScanner" class="btn btn-outline-secondary w-100 mt-2">
                <i class="bi bi-arrow-counterclockwise me-2"></i>
                Nouvelle recharge
            </button>

        </div>
    </div>

    {{-- OCR --}}
    <div wire:ignore class="mt-3 text-center" id="ocr-status">
        <small class="text-secondary">
            En attente d'une photographie...
        </small>
    </div>

</div>

@script
    <script>
        let ocrWorker = null;

        async function getOCRWorker() {
            if (ocrWorker) return ocrWorker;

            const Tesseract = await import(
                'https://cdn.jsdelivr.net/npm/tesseract.js@6/+esm'
            );

            ocrWorker = await Tesseract.createWorker('eng');

            await ocrWorker.setParameters({
                tessedit_char_whitelist: '0123456789',
                tessedit_pageseg_mode: '7' // Mode ligne de texte unique (souvent idéal pour un code de recharge)
            });

            return ocrWorker;
        }

        // Fonction pour prétraiter l'image (Contraste + Redimensionnement)
        function preprocessImage(imageSrc) {
            return new Promise((resolve) => {
                const img = new Image();
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');

                    // Redimensionner pour optimiser la vitesse et la précision (ex: largeur max 800px)
                    let width = img.width;
                    let height = img.height;
                    const maxWidth = 800;
                    if (width > maxWidth) {
                        height = Math.round((height * maxWidth) / width);
                        width = maxWidth;
                    }

                    canvas.width = width;
                    canvas.height = height;

                    // Dessiner l'image en niveaux de gris et augmenter le contraste
                    ctx.drawImage(img, 0, 0, width, height);

                    // Optionnel : Récupérer les données pour accentuer le contraste si besoin, 
                    // ou simplement passer l'image redimensionnée à Tesseract qui gère bien le noir et blanc.
                    resolve(canvas.toDataURL('image/jpeg', 0.9));
                };
                img.src = imageSrc;
            });
        }

        $wire.on('photo-ready', async (event) => {
            const status = document.getElementById('ocr-status');

            try {
                status.innerHTML = `
                <div class="spinner-border spinner-border-sm text-primary"></div>
                <small class="ms-2">Prétraitement et analyse...</small>
            `;

                // Prétraiter l'image avant l'OCR
                const processedImage = await preprocessImage(event.image);

                const worker = await getOCRWorker();
                const result = await worker.recognize(processedImage);

                const digits = result.data.text.replace(/\D/g, '');

                await $wire.set('code', digits);

                if (digits.length > 0) {
                    status.innerHTML = `
                    <span class="text-success">
                        <i class="bi bi-check-circle-fill"></i>
                        Code détecté avec succès !
                    </span>
                `;
                } else {
                    status.innerHTML = `
                    <span class="text-warning">
                        Aucun chiffre détecté. Assure-toi de bien cadrer les chiffres.
                    </span>
                `;
                }

            } catch (error) {
                console.error(error);
                status.innerHTML = `
                <span class="text-danger">
                    Erreur pendant la reconnaissance OCR.
                </span>
            `;
            }
        });

        $wire.on('ocr-error', (event) => {
            document.getElementById('ocr-status').innerText = event.message;
        });
    </script>
@endscript
