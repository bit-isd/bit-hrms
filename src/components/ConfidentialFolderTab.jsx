import React, { useState } from "react";
import axios from "axios";
import { Download, LockKeyhole, Plus, Trash2 } from "lucide-react";
import { useDialog } from "./AppDialog";

export default function ConfidentialFolderTab({ staff, apiBase = "/api/v1", canManage }) {
  const dialog = useDialog();
  const [password, setPassword] = useState("");
  const [unlocked, setUnlocked] = useState(false);
  const [ratings, setRatings] = useState([]);
  const [documents, setDocuments] = useState([]);
  const [error, setError] = useState("");
  const [rating, setRating] = useState({ rating_year: new Date().getFullYear(), rating: 3, comments: "" });
  const [file, setFile] = useState(null);
  const [title, setTitle] = useState("");
  const [category, setCategory] = useState("PERFORMANCE");
  const endpoint = `${apiBase}/staff/${staff.id}/confidential`;

  const unlock = async (event) => {
    event.preventDefault(); setError("");
    try {
      const response = await axios.post(endpoint, { password });
      setRatings(response.data.data.ratings); setDocuments(response.data.data.documents); setUnlocked(true);
    } catch (requestError) { setError(requestError.response?.data?.message || "Access could not be verified."); }
  };
  const saveRating = async (event) => {
    event.preventDefault(); setError("");
    try {
      await axios.post(`${endpoint}/ratings`, { ...rating, password });
      const response = await axios.post(endpoint, { password }); setRatings(response.data.data.ratings);
    } catch (requestError) { setError(requestError.response?.data?.message || "Rating could not be saved."); }
  };
  const upload = async (event) => {
    event.preventDefault(); if (!file) return;
    const data = new FormData(); data.append("password", password); data.append("title", title); data.append("category", category); data.append("file", file);
    try { const response = await axios.post(`${endpoint}/documents`, data); setDocuments([response.data.data, ...documents]); setFile(null); setTitle(""); }
    catch (requestError) { setError(requestError.response?.data?.message || "Upload failed."); }
  };
  const download = async (document) => {
    try {
      const response = await axios.post(`${endpoint}/documents/${document.id}/download`, { password }, { responseType: "blob" });
      const url = URL.createObjectURL(response.data); const link = window.document.createElement("a"); link.href = url; link.download = document.file_name; link.click(); URL.revokeObjectURL(url);
    } catch { setError("The confidential document could not be opened."); }
  };
  const deleteDocument = async (document) => {
    if (!await dialog.confirm({ title: "Delete confidential document", message: `Delete ${document.title}?`, confirmText: "Delete", tone: "danger" })) return;
    try { await axios.delete(`${endpoint}/documents/${document.id}`, { data: { password } }); setDocuments(documents.filter((item) => item.id !== document.id)); }
    catch (requestError) { setError(requestError.response?.data?.message || "Delete failed."); }
  };
  const deleteRating = async (item) => {
    if (!await dialog.confirm({ title: "Delete rating", message: `Delete the ${item.rating_year} rating?`, confirmText: "Delete", tone: "danger" })) return;
    try { await axios.delete(`${endpoint}/ratings/${item.id}`, { data: { password } }); setRatings(ratings.filter((entry) => entry.id !== item.id)); }
    catch (requestError) { setError(requestError.response?.data?.message || "Delete failed."); }
  };

  if (!unlocked) return <form onSubmit={unlock} className="max-w-lg mx-auto my-8 space-y-3 border border-amber-300 bg-amber-50 p-5 rounded">
    <LockKeyhole className="text-amber-800" size={22} /><h3 className="font-bold">Confidential Personnel Folder</h3>
    <p className="text-xs text-slate-600">Re-enter your administrator password to access this officer’s restricted records. Access is audited.</p>
    {error && <p role="alert" className="text-rose-700 text-xs">{error}</p>}
    <label className="block text-xs font-semibold">Administrator password<input type="password" required autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} className="mt-1 w-full border rounded px-3 py-2" /></label>
    <button className="px-3 py-2 bg-slate-900 text-white rounded text-xs font-semibold">Unlock folder</button>
  </form>;

  return <section className="space-y-5 text-sm">
    <header className="flex justify-between"><div><h3 className="font-bold">Confidential Folder</h3><p className="text-xs text-slate-500">Annual performance ratings and restricted documents</p></div><button type="button" onClick={() => { setUnlocked(false); setPassword(""); setRatings([]); setDocuments([]); }} className="px-3 py-1.5 border rounded text-xs">Lock folder</button></header>
    {error && <p role="alert" className="p-2 bg-rose-50 text-rose-800 border rounded text-xs">{error}</p>}
    {canManage && <form onSubmit={saveRating} className="p-3 border rounded space-y-3">
      <h4 className="font-bold text-sm">Annual Performance Rating</h4>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <label className="text-xs">Year<input type="number" min="2000" max={new Date().getFullYear()} required value={rating.rating_year} onChange={(event) => setRating({ ...rating, rating_year: event.target.value })} className="mt-1 w-full border rounded p-2" /></label>
        <label className="text-xs">Rating (1–5)<input type="number" min="1" max="5" step="0.1" required value={rating.rating} onChange={(event) => setRating({ ...rating, rating: event.target.value })} className="mt-1 w-full border rounded p-2" /></label>
        <label className="text-xs">Assessment notes<input value={rating.comments} onChange={(event) => setRating({ ...rating, comments: event.target.value })} className="mt-1 w-full border rounded p-2" /></label>
      </div><button className="px-3 py-2 bg-emerald-700 text-white rounded text-xs font-semibold">Save rating</button>
    </form>}
    <section className="border rounded p-3"><h4 className="font-bold text-sm mb-3">Performance progression</h4>{ratings.length ? <div className="flex items-end gap-3 h-40 border-b border-l p-3">{ratings.map((item) => <div key={item.id} className="flex-1 h-full flex flex-col justify-end items-center gap-1" title={`${item.rating_year}: ${item.rating}/5`}><span className="text-[10px]">{Number(item.rating).toFixed(1)}</span><div className="w-full max-w-12 bg-emerald-700 rounded-t" style={{ height: `${Number(item.rating) * 20}%` }} /><span className="text-[10px]">{item.rating_year}</span></div>)}</div> : <p className="text-xs text-slate-500">No annual ratings have been entered.</p>}
      <div className="mt-3 divide-y">{ratings.map((item) => <div key={item.id} className="py-2 flex justify-between text-xs"><span><b>{item.rating_year}</b> · {item.rating}/5 · {item.comments || "No notes"}</span>{canManage && <button type="button" title="Delete rating" aria-label={`Delete ${item.rating_year} rating`} onClick={() => deleteRating(item)}><Trash2 size={14} /></button>}</div>)}</div>
    </section>
    {canManage && <form onSubmit={upload} className="p-3 border rounded grid grid-cols-1 sm:grid-cols-4 gap-3 items-end"><label className="text-xs">Document title<input required value={title} onChange={(event) => setTitle(event.target.value)} className="mt-1 w-full border rounded p-2" /></label><label className="text-xs">Category<select value={category} onChange={(event) => setCategory(event.target.value)} className="mt-1 w-full border rounded p-2"><option value="PERFORMANCE">Performance</option><option value="MEDICAL">Medical</option><option value="PERSONNEL">Personnel</option><option value="OTHER">Other</option></select></label><label className="text-xs">File<input type="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={(event) => setFile(event.target.files?.[0] || null)} className="mt-1 w-full text-xs" /></label><button className="px-3 py-2 bg-emerald-700 text-white rounded text-xs font-semibold"><Plus size={14} className="inline" /> Upload</button></form>}
    <section className="border rounded divide-y"><h4 className="font-bold text-sm p-3">Confidential documents</h4>{documents.length ? documents.map((document) => <div key={document.id} className="px-3 py-2 flex items-center gap-2 text-xs"><span className="flex-1">{document.title} · {document.category} · {document.file_name}</span><button type="button" title="Download document" aria-label={`Download ${document.title}`} onClick={() => download(document)}><Download size={15} /></button>{canManage && <button type="button" title="Delete document" aria-label={`Delete ${document.title}`} onClick={() => deleteDocument(document)}><Trash2 size={15} /></button>}</div>) : <p className="p-3 text-xs text-slate-500">No confidential documents.</p>}</section>
  </section>;
}