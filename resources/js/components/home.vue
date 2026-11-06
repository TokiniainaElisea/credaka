<template>
  <div class="container py-4" style="max-width: 540px;">
    
    <!-- En-tête -->
    <div class="text-center mb-4">
      <h4 class="fw-bold">Scanner de crédit</h4>
      <p class="text-muted small">Prenez une photo de la séquence de chiffres</p>
    </div>

    <!-- Zone des boutons d'action -->
    <div v-if="!isProcessing" class="d-grid gap-3 mb-4">
      <button 
        @click="takePhoto" 
        class="btn btn-primary btn-lg d-flex align-items-center justify-content-center gap-2"
      >
        <i class="bi bi-camera"></i> Prendre une photo
      </button>
      
      <button 
        @click="pickFromGallery" 
        class="btn btn-outline-secondary btn-lg d-flex align-items-center justify-content-center gap-2"
      >
        <i class="bi bi-image"></i> Choisir dans la galerie
      </button>
    </div>

    <!-- Indicateur de chargement -->
    <div v-if="isProcessing" class="text-center py-5">
      <div class="spinner-border text-primary mb-3" role="status"></div>
      <p class="text-muted">Analyse de l'image en cours...</p>
    </div>

    <!-- Résultat -->
    <div v-if="resultCode && !isProcessing" class="card border-success mb-4">
      <div class="card-body text-center">
        <p class="text-muted mb-1 small">Code détecté</p>
        <h2 class="display-5 fw-bold text-success mb-0">{{ resultCode }}</h2>
      </div>
    </div>

    <!-- Message d'erreur -->
    <div v-if="errorMessage && !isProcessing" class="alert alert-warning" role="alert">
      {{ errorMessage }}
    </div>

    <!-- Bouton de composition USSD (fixé en bas) -->
    <div 
      v-if="resultCode && !isProcessing" 
      class="position-fixed bottom-0 start-0 end-0 p-3 bg-white border-top"
      style="max-width: 540px; margin: 0 auto;"
    >
      <button 
        @click="composeUSSD" 
        class="btn btn-success btn-lg w-100 d-flex align-items-center justify-content-center gap-2"
      >
        <i class="bi bi-telephone"></i> Composer *{{ resultCode }}#
      </button>
    </div>

    <!-- Espace pour éviter que le contenu soit caché par le bouton fixe -->
    <div v-if="resultCode" style="height: 80px;"></div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import Tesseract from 'tesseract.js';
import { Camera, On, Off, Events } from '#nativephp';

// --- États réactifs ---
const isProcessing = ref(false);
const resultCode = ref('');
const errorMessage = ref('');
const worker = ref(null);
const pendingSource = ref(null); // 'camera' ou 'gallery'

// --- Méthodes ---

const resetState = () => {
  resultCode.value = '';
  errorMessage.value = '';
  isProcessing.value = false;
};

const takePhoto = async () => {
  resetState();
  pendingSource.value = 'camera';
  await Camera.getPhoto();
};

const pickFromGallery = async () => {
  resetState();
  pendingSource.value = 'gallery';
  await Camera.pickImages().images().single();
};

const processImage = async (imagePath) => {
  isProcessing.value = true;
  errorMessage.value = '';

  try {
    const { data: { text } } = await worker.value.recognize(imagePath);
    
    // Nettoyage : on ne garde que les chiffres
    const cleanCode = text.replace(/\D/g, '');
    
    if (cleanCode.length > 0) {
      resultCode.value = cleanCode;
    } else {
      errorMessage.value = "Aucun chiffre détecté. Réessayez avec une meilleure lumière.";
    }
  } catch (err) {
    console.error(err);
    errorMessage.value = "Erreur lors de l'analyse de l'image.";
  } finally {
    isProcessing.value = false;
  }
};

// --- Gestionnaires d'événements natifs ---

const handlePhotoTaken = (payload) => {
  if (pendingSource.value !== 'camera') return;
  pendingSource.value = null;
  processImage(payload.path);
};

const handleMediaSelected = (payload) => {
  if (pendingSource.value !== 'gallery') return;
  pendingSource.value = null;
  
  if (payload.files && payload.files.length > 0) {
    processImage(payload.files[0]);
  } else {
    errorMessage.value = "Aucune image sélectionnée.";
  }
};

const composeUSSD = () => {
  if (!resultCode.value) return;
  window.location.href = `tel:*${resultCode.value}#`;
};

// --- Cycle de vie ---

onMounted(async () => {
  // Initialisation du worker Tesseract en mode chiffres uniquement
  worker.value = await Tesseract.createWorker('eng');
  await worker.value.setParameters({
    tessedit_char_whitelist: '0123456789',
    tessedit_pageseg_mode: Tesseract.PSM.SINGLE_LINE,
  });

  // Écoute des événements natifs
  On(Events.Camera.PhotoTaken, handlePhotoTaken);
  On(Events.Gallery.MediaSelected, handleMediaSelected);
});

onBeforeUnmount(() => {
  if (worker.value) worker.value.terminate();
  Off(Events.Camera.PhotoTaken, handlePhotoTaken);
  Off(Events.Gallery.MediaSelected, handleMediaSelected);
});
</script>