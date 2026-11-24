import React, { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { KeyRound, TriangleAlert } from "lucide-react";

const DialogContext = createContext(null);

// In-app replacement for window.confirm / window.prompt. Both return promises:
// confirm resolves true/false, prompt resolves the entered text or null when cancelled.
export function DialogProvider({ children }) {
  const [dialog, setDialog] = useState(null);
  const [value, setValue] = useState("");
  const inputRef = useRef(null);
  const confirmRef = useRef(null);

  const open = useCallback((kind, options) => new Promise((resolve) => {
    setValue("");
    setDialog({ kind, resolve, ...(typeof options === "string" ? { message: options } : options) });
  }), []);

  const confirm = useCallback((options) => open("confirm", options), [open]);
  const prompt = useCallback((options) => open("prompt", options), [open]);

  const close = (result) => {
    dialog?.resolve(result);
    setDialog(null);
  };

  useEffect(() => {
    if (!dialog) return undefined;
    (dialog.kind === "prompt" ? inputRef.current : confirmRef.current)?.focus();
    const onKey = (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        dialog.resolve(dialog.kind === "prompt" ? null : false);
        setDialog(null);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [dialog]);

  const danger = dialog?.tone === "danger";
  const submit = (event) => {
    event.preventDefault();
    close(dialog.kind === "prompt" ? value : true);
  };

  return (
    <DialogContext.Provider value={{ confirm, prompt }}>
      {children}
      {dialog && (
        <div className="no-print fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/60 p-4" onMouseDown={(event) => event.target === event.currentTarget && close(dialog.kind === "prompt" ? null : false)}>
          <form onSubmit={submit} role="dialog" aria-modal="true" aria-labelledby="app-dialog-title" className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-2xl">
            <div className="flex items-start gap-3">
              <span className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${danger ? "bg-rose-100 text-rose-700" : "bg-emerald-100 text-emerald-800"}`}>
                {dialog.kind === "prompt" ? <KeyRound size={20} /> : <TriangleAlert size={20} />}
              </span>
              <div className="min-w-0 flex-1">
                <h2 id="app-dialog-title" className="text-base font-bold text-slate-900">{dialog.title || (dialog.kind === "prompt" ? "Confirmation required" : "Please confirm")}</h2>
                {dialog.message && <p className="mt-1 text-sm leading-relaxed text-slate-600 break-words">{dialog.message}</p>}
              </div>
            </div>
            {dialog.kind === "prompt" && (
              <label className="mt-4 block">
                <span className="text-xs font-bold uppercase tracking-wide text-slate-500">{dialog.label || "Value"}</span>
                <input
                  ref={inputRef}
                  type={dialog.inputType || "text"}
                  autoComplete={dialog.inputType === "password" ? "current-password" : "off"}
                  value={value}
                  onChange={(event) => setValue(event.target.value)}
                  className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20"
                />
              </label>
            )}
            <div className="mt-5 flex justify-end gap-2">
              <button type="button" onClick={() => close(dialog.kind === "prompt" ? null : false)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{dialog.cancelText || "Cancel"}</button>
              <button
                ref={confirmRef}
                type="submit"
                disabled={dialog.kind === "prompt" && dialog.required !== false && !value}
                className={`rounded-lg px-4 py-2 text-sm font-bold text-white disabled:opacity-50 ${danger ? "bg-rose-700 hover:bg-rose-800" : "bg-emerald-700 hover:bg-emerald-800"}`}
              >{dialog.confirmText || "Continue"}</button>
            </div>
          </form>
        </div>
      )}
    </DialogContext.Provider>
  );
}

export function useDialog() {
  const context = useContext(DialogContext);
  if (!context) throw new Error("useDialog must be used inside DialogProvider.");
  return context;
}
