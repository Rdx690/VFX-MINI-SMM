// Firebase + ImgBB configuration for VFX MINI SMM.
// Uses the existing client-side Firebase config supplied for this panel.
// Do not put Firebase service-account/private-key JSON or admin credentials in this file.

const firebaseConfig = {
  apiKey: "AIzaSyDN_bht9IgtqUj100bBUE74zWzm0JWXxfE",
  authDomain: "smm-panel-86035.firebaseapp.com",
  databaseURL: "https://smm-panel-86035-default-rtdb.europe-west1.firebasedatabase.app",
  projectId: "smm-panel-86035",
  storageBucket: "smm-panel-86035.firebasestorage.app",
  messagingSenderId: "800960046248",
  appId: "1:800960046248:web:81ef85047450e2e0878e9d",
  measurementId: "G-GZMKXT4ZZ5"
};

if (typeof firebase !== "undefined") {
  if (!firebase.apps || firebase.apps.length === 0) {
    firebase.initializeApp(firebaseConfig);
  }
  window.auth = firebase.auth();
  window.db = firebase.database();
} else {
  console.error("Firebase SDK is missing. Load Firebase compat SDK before firebase.js.");
}

// ImgBB key is used by browser uploads; restrict usage where possible and rotate if abused.
const IMGBB_KEY = "172dabb7ec93ead71d6b9e5631f3155d";

async function uploadImageToImgBB(file, expirationSeconds = 0) {
  if (!IMGBB_KEY.trim()) throw new Error("ImgBB API key is missing.");
  if (!(file instanceof Blob)) throw new Error("Please select a valid image file.");

  const formData = new FormData();
  formData.append("image", file);
  if (Number.isInteger(expirationSeconds) && expirationSeconds > 0) {
    formData.append("expiration", String(expirationSeconds));
  }

  const endpoint = "https://api.imgbb.com/1/upload?key=" + encodeURIComponent(IMGBB_KEY);
  const response = await fetch(endpoint, { method: "POST", body: formData, cache: "no-store" });
  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new Error("ImgBB returned an unreadable response.");
  }
  if (!response.ok || payload?.success !== true || !payload?.data?.url) {
    throw new Error(payload?.error?.message || "Image upload failed. Check the API key.");
  }
  return payload.data.url;
}

window.uploadImageToImgBB = uploadImageToImgBB;
window.IMGBB_KEY = IMGBB_KEY;
