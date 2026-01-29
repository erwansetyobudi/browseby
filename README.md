# BrowseBy Plugin for SLiMS by Erwan Setyo Budi

Plugin **BrowseBy** adalah plugin OPAC untuk **SLiMS Bulian (>= 9.3.0)** yang menyediakan fitur penelusuran koleksi berbasis indeks (A–Z) dengan performa ringan menggunakan **file cache**.

Plugin ini dirancang untuk membantu pengguna OPAC menelusuri koleksi secara terstruktur dan cepat tanpa membebani database.

---

# Fitur

Plugin ini menyediakan beberapa mode *Browse By* di OPAC:

- **Browse by Author**  
  Menelusuri koleksi berdasarkan nama pengarang (`mst_author.author_name`)

- **Browse by Year (Tahun Terbit)**  
  Menelusuri koleksi berdasarkan tahun terbit (`biblio.publish_year`)

- **Browse by Topic**  
  Menelusuri koleksi berdasarkan topik/subjek (`mst_topic.topic`)

- **Browse by GMD**  
  Menelusuri koleksi berdasarkan jenis bahan pustaka (`mst_gmd.gmd_name`)

- **Browse by Collection Type**  
  Menelusuri koleksi berdasarkan tipe koleksi (`mst_coll_type.coll_type_name` via tabel `item`)

### Informasi yang ditampilkan
- Nama pengarang
- Tahun terbit
- GMD
- Judul (klik menuju halaman detail OPAC)
- Tempat terbit & penerbit
- Informasi tambahan item (lokasi, status, jumlah eksemplar – khusus koleksi tipe)

### Optimasi Performa
- Menggunakan **file cache** di:
