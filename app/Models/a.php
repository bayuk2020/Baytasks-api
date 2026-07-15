/start (ini jika aku pengen manual)
keluar pilihan 
Tasks | Journal | Habit | Finance
Jika di klik tasks
pilih tombol boards kerjaan atau personal 
tampilkan seluruh tasks yang belum selesai dari boards yang dipilih
jika di klik salah satu task maka menuju -- SESI Jika  Salah satu Task di Klik --
(melanjutkan yang setiap 1 jam tanya)
Bot: "Halo Bayu, saat ini Anda sedang melakukan aktivitas apa?"[Tombol Inline: 💻 Kerja] [📖 Belajar] [☕ Santai] [🌐 Lainnya]
Kondisi Logika Setelah Tombol Diklik:
Jika klik 💻 Kerja atau 📖 Belajar:
Bot Balas : lagi ngerjain apa?
tampil daftar tasks dari boards id 2 dalam bentuk tombol dan 1 tombol lainnya
ketika di klik salah satu, 
bot balas : 
Oke sip, sampai mana ngerjainnya?
-- SESI Jika  Salah satu Task di Klik --
Tampil deskripsi, notes, dan daftar subtask nya (jika yang udah kecentang berarti tampilannya:
✅Subtask 1 (dicoret)
✅Subtask 2 (dicoret)
❌Subtask 3 (belum dikerjakan)
ketika aku ketik 1 atau 2 (yang udah dikerjain) maka
bot balas "kan udah dikerjain, ada tambahan kah?"
jika aku : ngetik iya yang kemarin belum selesai soalnya
bot balas Oke silahkan lanjutkan, Semangat yaa (kasih emot)
jika aku : ngetik 3 
bot balas Oke silahkan lanjutkan, Semangat yaa (kasih emot)
jika aku : iya ada tambahan blablabla (berarti itu Subtask 4)
bot : oke aku akan tambahkan (bot membuat subtask 4). silahkan lanjutkan, Semangat yaa (kasih emot)
Setiap sesi tasks ada tombol Selesaikan (jika di klik maka tasks done dan balas "Mantap, lanjutkan tugas yang lain"), mundurkan deadline (otomatis di bawahnya nanti aku akan menambahkan tanggal atau jam nya), Ubah prioritas (tampil tombol Low Med High Urgent), Ubah Deskripsi (menimpa yang udah ada), Ubah notes (menimpa yang udah ada), ganti tugas lain (otomatis mengembalikan daftar tasks yang belum selesai dan jika di klik salah satu, tampil semuanya di atas)
-- Akhir SESI Salah satu Task --
-- SESI Jika di Klik Lainnya --
bot : "kok ga ngerjain yang di To Do List? lagi ngerjain apa?"
me : ngetik kerjaan yang lain, Otomatis tambahin ke tasks kerjaan yang baru
misal "Aku lagi ngerjain Komptuter nya Mbak Fitri lagi error chargernya mati, bentar aja 10 menitan (biasanya kayak gini tu priority nya urgent)"
bot : oke aku tambahin ke daftar task mu hari ini, aku ingetin / tanya 10 menit lagi ya udah selesai atau belum
ada tombol: Oke
bot : semangat, lanjutin (atau nanti kita bikin array yang bikin kata kata motivasi buat semangat kerja terus bot pilih salah satu dari sana)
-- Setelah 10 menit -- 
bot : Hai, udah selesai kah yang tugas benerin komputer Mbak Fitri nya?
tombol selesai (jika udah, pindah done dan kirim kalimat dari array jika udah selesai mengerjakan sesuatu) dan belum (jika belum ulangi lagi bertanya setelah 10 menit) 
#Catatan : inget, 10 menit ini bisa berubah 5 menit, atau 15 menit tergantung aku bilangnya berapa
-- Akhir SESI Jika di Klik Lainnya --
simpan ke tabel memories ringkasan tentang percakapan di atas.
contoh "User sedang mengerjakan tugas ... dengan subtask ... dari jam ... sampai jam ..." 
Bot Balas: "Selamat melanjutkan aktivitas. Semoga produktif, Bay!"
Jika klik ☕ Santai:
Bot : Wuih lagi santai kawan, lagi ape lu?
Me : Lagi nonton youtube aja, lihat reels / channel nya ferry irwandi
Sistem simpan ke memories.
Logic PHP Backend: Menjalankan query ke tabel tasks untuk mencari tugas yang belum selesai (completed_at IS NULL) khusus di Board ID 2 (Kerjaan) dan Board ID 4 (Personal).
Bot Balas: * Jika bersih: "Selamat beristirahat, Bayu. Semua tugas Anda terpantau aman."
Jika ada tugas nunggak: "Baiklah. Namun, sistem mendeteksi ada tugas yang belum Anda selesaikan:\n• Kerjaan: [Nama Task di Board 2]\n• Personal: [Nama Task di Board 4]\n\nJangan lupa dikerjakan setelah ini ya, su!"
Jika klik 🌐 Lainnya:
Bot Balas: "Silakan ketik aktivitas Anda saat ini secara manual:"
Teks ketikan lu selanjutnya akan ditangkap oleh controller dan disimpan ke tabel memories.