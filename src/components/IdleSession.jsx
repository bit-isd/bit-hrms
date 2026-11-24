import React, { useEffect, useState } from "react";
import axios from "axios";
import { X } from "lucide-react";

const ACTIVITY_KEY = "bit_hrms_last_activity";
const HOUR = 60 * 60 * 1000;

export default function IdleSession({ token, onExpire }) {
  const [warning, setWarning] = useState(null);

  useEffect(() => {
    let warned = 0;
    let lastSaved = 0;
    let lastPing = 0;
    let closed = false;
    if (!localStorage.getItem(ACTIVITY_KEY)) localStorage.setItem(ACTIVITY_KEY, String(Date.now()));

    const expire = () => {
      if (closed) return;
      closed = true;
      axios.post("/api/v1/auth/logout").catch(() => {});
      onExpire("Session expired after one hour of inactivity. Please sign in again.");
    };

    const check = () => {
      if (!localStorage.getItem("bit_hrms_token")) {
        onExpire("Your session has ended. Please sign in again.");
        return;
      }
      const idle = Date.now() - Number(localStorage.getItem(ACTIVITY_KEY));
      if (idle >= HOUR) return expire();
      const level = idle >= 59 * 60000 ? 3 : idle >= 55 * 60000 ? 2 : idle >= 50 * 60000 ? 1 : 0;
      if (level > warned) {
        warned = level;
        setWarning({ id: Date.now(), minutes: Math.max(1, Math.ceil((HOUR - idle) / 60000)) });
      } else if (level === 0) {
        warned = 0;
      }
    };

    const activity = () => {
      const now = Date.now();
      if (now - Number(localStorage.getItem(ACTIVITY_KEY)) >= HOUR) return expire();
      if (now - lastSaved >= 5000) {
        localStorage.setItem(ACTIVITY_KEY, String(now));
        lastSaved = now;
        warned = 0;
      }
      if (now - lastPing >= 5 * 60000) {
        lastPing = now;
        verifyAccount(true);
      }
    };

    let checking = false;
    const verifyAccount = async (active = false) => {
      if (closed || checking) return;
      checking = true;
      try {
        await axios.get(active ? "/api/v1/auth/me" : "/api/v1/auth/session", {
          headers: { Authorization: `Bearer ${token}` },
        });
      } catch (error) {
        if (closed) return;
        if (error.response?.status === 401) {
          closed = true;
          onExpire(error.response.data?.message || "Your session has ended. Please sign in again.");
        } else {
          console.error("Could not verify account access", error);
        }
      } finally {
        checking = false;
      }
    };

    const events = ["pointerdown", "pointermove", "keydown", "scroll", "touchstart"];
    events.forEach((event) => window.addEventListener(event, activity, { passive: true }));
    window.addEventListener("storage", check);
    window.addEventListener("focus", check);
    const timer = window.setInterval(check, 1000);
    const accountTimer = window.setInterval(verifyAccount, 10000);
    verifyAccount();
    check();
    return () => {
      closed = true;
      events.forEach((event) => window.removeEventListener(event, activity));
      window.removeEventListener("storage", check);
      window.removeEventListener("focus", check);
      window.clearInterval(timer);
      window.clearInterval(accountTimer);
    };
  }, [token]);

  useEffect(() => {
    if (!warning) return;
    const timer = window.setTimeout(() => setWarning(null), 6000);
    return () => window.clearTimeout(timer);
  }, [warning]);

  if (!warning) return null;
  return (
    <div role="status" className="idle-warning fixed z-[100] right-4 top-4 max-w-sm flex items-start gap-3 p-3 bg-amber-100 text-amber-950 border border-amber-300 rounded shadow-lg text-sm">
      <span>Your session will expire in about {warning.minutes} minute{warning.minutes === 1 ? "" : "s"} due to inactivity.</span>
      <button type="button" title="Dismiss warning" aria-label="Dismiss warning" onClick={() => setWarning(null)}><X size={16} /></button>
    </div>
  );
}