# Docstore - Document Vault & Cryptographic Verification Service

Sub-project `docstore` berfungsi sebagai pusat arsip dokumen resmi digital (Surat Cuti, SP3, dll.) RS Bintang Amin. Service ini menyediakan API penerimaan data dokumen dari `office` dan mempublikasikan API verifikasi keaslian dokumen berbasis tanda tangan digital kriptografis RSA SHA-256.

---

## 🛠️ Tech Stack & Domain

- **Framework:** Laravel 12 (API Mode)
- **Engine Kriptografi:** PHP OpenSSL (RSA SHA-256)
- **Database:** MySQL (`docstore`)
- **Keamanan Sync:** Bearer Token Header (`DOCSTORE_API_TOKEN`)
- **Domain / Port Dev:** `http://localhost:8000` / `http://docstore.test`

---

## 🔑 Fitur Utama & API Endpoints

1. **Document Sync API (`POST /api/documents`)**:
   - Menerima payload dokumen ter-approve dan daftar tanda tangan digital dari `office` (`DocstoreSyncService`).
   - Autentikasi ketat menggunakan Bearer Token `DOCSTORE_API_TOKEN`.
   - Meng-upsert data pada tabel `documents` dan `document_signatures`.
2. **Public Verification API (`GET /api/verify?hash={hash}`)**:
   - Endpoint publik (CORS Allowed) yang dipanggil oleh portal web `verify`.
   - Melakukan eksekusi `openssl_verify()` menggunakan `public_key` terhadap `original_data` untuk membuktikan keaslian dokumen.
   - **Fallback Mechanism:** Jika byte-level OpenSSL berbeda akibat normalisasi JSON MySQL, controller melakukan verifikasi `data_hash` (SHA-256 dari payload asli) untuk mengonfirmasi integritas dokumen.

---

## 🚀 Cara Jalankan di Development

1. **Instalasi Dependencies & Config**:
   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   ```
2. **Setup Database**:
   Pastikan database `docstore` sudah dibuat di MySQL, lalu sesuaikan `.env`:
   ```ini
   DB_DATABASE=docstore
   DOCSTORE_API_TOKEN=secret_docstore_verification_token_2026
   ```
   Jalankan migrasi:
   ```bash
   php artisan migrate
   ```
3. **Jalankan API Server**:
   ```bash
   php artisan serve --port=8000
   ```

---

## 📚 Referensi Arsitektur

- 🗺️ **[PROJECT_MAP.md](file:///d:/Intern/RSBA%20-%20Kerja%20Praktik/DMS/Tahap%201/PROJECT_MAP.md)**
- 🚀 **[HANDOVER_RUNNING_GUIDE.md](file:///d:/Intern/RSBA%20-%20Kerja%20Praktik/DMS/Tahap%201/HANDOVER_RUNNING_GUIDE.md)**
