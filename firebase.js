// FIREBASE CONFIGURATION & INITIALIZATION
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

// Initialize Firebase
firebase.initializeApp(firebaseConfig);

// Export Auth & DB globally
const auth = firebase.auth();
const db = firebase.database();

// ImgBB API Key (Global)
const IMGBB_KEY = "172dabb7ec93ead71d6b9e5631f3155d";

console.log("Firebase Connected via firebase.js");