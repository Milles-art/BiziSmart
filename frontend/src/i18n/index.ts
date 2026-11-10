import { useSyncExternalStore } from "react";
import { kiswahili } from "./kiswahili";
import { emailAuthKiswahili } from "./emailAuthKiswahili";
import { settingsKiswahili } from "./settingsKiswahili";

export type Language = "en" | "sw";
const preferenceKey = "bizismart-language";
const listeners = new Set<() => void>();
function readPreference(): Language {
  try {
    return localStorage.getItem(preferenceKey) === "sw" ? "sw" : "en";
  } catch {
    return "en";
  }
}
let language: Language = readPreference();
export function currentLanguage(): Language {
  return language;
}
export function setLanguage(value: Language): void {
  if (value !== "en" && value !== "sw") return;
  language = value;
  try {
    localStorage.setItem(preferenceKey, value);
  } catch {
    /* Language works when browser storage is unavailable. */
  }
  if (typeof document !== "undefined") document.documentElement.lang = value;
  listeners.forEach((listener) => listener());
}
/** Explicit message keys; business names, catalogue data and API identifiers stay untouched. */
export function translate(key: string, selected: Language = language): string {
  return selected === "sw" ? (settingsKiswahili[key] ?? emailAuthKiswahili[key] ?? kiswahili[key] ?? key) : key;
}
function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}
export function useLanguage() {
  const selected = useSyncExternalStore(
    subscribe,
    currentLanguage,
    () => "en" as const,
  );
  return {
    language: selected,
    setLanguage,
    t: (key: string) => translate(key, selected),
  };
}
if (typeof window !== "undefined") {
  document.documentElement.lang = language;
  window.addEventListener("storage", (event) => {
    if (event.key === preferenceKey || event.key === null) {
      language = readPreference();
      document.documentElement.lang = language;
      listeners.forEach((listener) => listener());
    }
  });
}
