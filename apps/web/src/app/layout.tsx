import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";

import { TooltipProvider } from "@pcle/ui/components/tooltip";

import VersionStamp from "@/components/version-stamp";
import { settingsCss } from "@/lib/colours";
import { getColourSettings } from "@/lib/wordpress";

import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

/*
 * Where this app is served from in production: the Armory.
 *
 * Metadata resolves relative URLs against this, so it has to be absolute and
 * it has to be the public host — without it Next falls back to the
 * per-deployment Vercel URL, which would leak into anything shared. Override
 * it for a preview or a self-hosted deployment.
 */
const SITE_URL =
  process.env.NEXT_PUBLIC_SITE_URL ?? "https://armory.thepen-and-swordkc.org";

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: "Platform CLE",
  description:
    "Continuing Legal Education on immigration habeas corpus, by The Pen & Sword.",
};

export default async function RootLayout({ children }: LayoutProps<"/">) {
  /*
   * Every badge's colour, as CSS custom properties, and how programme colours
   * are drawn. Here, once, so the Colours screen reaches every screen and
   * every client component without anything being passed down to them.
   */
  const colours = await getColourSettings();

  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
      data-colour-mark={colours.mark}
    >
      <body className="min-h-full flex flex-col">
        <style>{settingsCss(colours)}</style>
        <TooltipProvider>{children}</TooltipProvider>
        <VersionStamp />
      </body>
    </html>
  );
}
