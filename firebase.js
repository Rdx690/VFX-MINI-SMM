// Firebase + ImgBB configuration for VFX MINI SMM.
// Add your own values below before using Firebase or image uploads.
// Never commit private server credentials. Rotate any key that was previously exposed publicly.

const firebaseConfig = {
  apiKey: "",
  authDomain: "",
  databaseURL: "",
  projectId: "",
  storageBucket: "",
  messagingSenderId: "",
  appId: "",
  measurementId: ""
};

// Firebase is initialized only after the required project values are filled in.
if (
  typeof firebase !== "undefined" &&
  firebaseConfig.apiKey &&
  firebaseConfig.authDomain &&
  firebaseConfig.databaseURL &&
  firebaseConfig.projectId &&
  firebaseConfig.appId
) {
  if (!firebase.apps || firebase.apps.length === 0) {
    firebase.initializeApp(firebaseConfig);
  }

  window.auth = firebase.auth();
  window.db = firebase.database();
  console.log("Firebase initialized.");
} else {
  console.warn("Firebase config is blank or Firebase SDK is missing. Add your Firebase project values to firebase.js.");
}

// ImgBB API key: paste your NEW key from https://api.imgbb.com/ here.
// The previously committed key should be rotated before reuse.
const IMGBB_KEY = "";

/**
 * Upload an image file to ImgBB.
 * Usage: const imageUrl = await uploadImageToImgBB(fileInput.files[0]);
 * Returns the uploaded image URL, or throws a readable error.
 */
async function uploadImageToImgBB(file, expirationSeconds = 0) {
  if (!IMGBB_KEY.trim()) {
    throw new Error("ImgBB API key is missing. Add your key to IMGBB_KEY in firebase.js.");
  }
  if (!(file instanceof Blob)) {
    throw new Error("Please select a valid image file.");
  }

  const formData = new FormData();
  formData.append("image", file);

  if (Number.isInteger(expirationSeconds) && expirationSeconds > 0) {
    formData.append("expiration", String(expirationSeconds));
  }

  const endpoint = "https://api.imgbb.com/1/upload?key=" + encodeURIComponent(IMGBB_KEY);
  const response = await fetch(endpoint, {
    method: "POST",
    body: formData,
    cache: "no-store"
  });

  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new Error("ImgBB returned an unreadable response.");
  }

  if (!response.ok || payload?.success !== true || !payload?.data?.url) {
    const message = payload?.error?.message || "Image upload failed. Check the API key and try again.";
    throw new Error(message);
  }

  return payload.data.url;
}

// Keep the helper available to existing plain-script pages.
window.uploadImageToImgBB = uploadImageToImgBB;
window.IMGBB_KEY = IMGBB_KEY;
