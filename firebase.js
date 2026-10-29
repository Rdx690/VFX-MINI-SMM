// Add your Firebase project configuration here when you're ready.
// Keep credentials/API keys out of Git if they are private; use environment variables on the server.
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

// Initialization requires a completed Firebase config.
if (firebaseConfig.apiKey && firebaseConfig.authDomain && firebaseConfig.databaseURL && firebaseConfig.projectId && firebaseConfig.appId) {
  firebase.initializeApp(firebaseConfig);
  const auth = firebase.auth();
  const db = firebase.database();
  window.auth = auth;
  window.db = db;
  console.log("Firebase initialized");
} else {
  console.warn("Firebase config is blank. Add your project values to firebase.js before using Firebase features.");
}

// Add your ImgBB API key later if image uploads need it.
const IMGBB_KEY = "";
