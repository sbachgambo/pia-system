// Minimal promise wrapper around IndexedDB — no dependency, just enough for
// the four stores in brief §6.

const DB_NAME = 'pia';
const DB_VERSION = 1;

/** @type {Promise<IDBDatabase>|null} */
let dbPromise = null;

export const STORES = {
  inspections: 'inspections_queue', // keyPath: uuid
  attachments: 'attachments_queue', // keyPath: id (client_uuid)
  reference: 'reference_cache',     // keyPath: key
  auth: 'auth_cache',               // keyPath: key
};

function open() {
  if (dbPromise) return dbPromise;

  dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);

    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORES.inspections)) {
        db.createObjectStore(STORES.inspections, { keyPath: 'uuid' });
      }
      if (!db.objectStoreNames.contains(STORES.attachments)) {
        const s = db.createObjectStore(STORES.attachments, { keyPath: 'id' });
        s.createIndex('by_inspection', 'inspection_uuid', { unique: false });
      }
      if (!db.objectStoreNames.contains(STORES.reference)) {
        db.createObjectStore(STORES.reference, { keyPath: 'key' });
      }
      if (!db.objectStoreNames.contains(STORES.auth)) {
        db.createObjectStore(STORES.auth, { keyPath: 'key' });
      }
    };

    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });

  return dbPromise;
}

function tx(store, mode, fn) {
  return open().then(
    (db) =>
      new Promise((resolve, reject) => {
        const t = db.transaction(store, mode);
        const s = t.objectStore(store);
        let result;
        Promise.resolve(fn(s)).then((r) => {
          result = r;
        });
        t.oncomplete = () => resolve(result);
        t.onerror = () => reject(t.error);
        t.onabort = () => reject(t.error);
      }),
  );
}

function reqToPromise(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

export const idb = {
  get(store, key) {
    return tx(store, 'readonly', (s) => reqToPromise(s.get(key)));
  },
  getAll(store) {
    return tx(store, 'readonly', (s) => reqToPromise(s.getAll()));
  },
  getAllByIndex(store, index, value) {
    return tx(store, 'readonly', (s) => reqToPromise(s.index(index).getAll(value)));
  },
  put(store, value) {
    return tx(store, 'readwrite', (s) => reqToPromise(s.put(value)));
  },
  delete(store, key) {
    return tx(store, 'readwrite', (s) => reqToPromise(s.delete(key)));
  },
  clear(store) {
    return tx(store, 'readwrite', (s) => reqToPromise(s.clear()));
  },
};
