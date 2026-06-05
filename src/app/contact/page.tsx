import React from "react";
import { Metadata } from "next";
import { getContactPage, getFormSettings } from "@/lib/graphql";
import * as cheerio from "cheerio";
import Image from "next/image";
import Header from "@/components/Header";
import { FadeIn } from "@/components/FadeIn";
import parse from "html-react-parser";
import ContactForm from "@/components/ContactForm";

export const dynamic = "force-dynamic";

export async function generateMetadata(): Promise<Metadata> {
  const pageData = await getContactPage();
  if (!pageData || !pageData.seo) return {};

  const { seo } = pageData;
  return {
    title: seo.title,
    description: seo.description,
  };
}

export default async function ContactPage() {
  const pageData = await getContactPage();
  const formSettings = await getFormSettings();
  const formFields = formSettings?.fields || [];
  const formTitle = formSettings?.form_title || "";

  if (!pageData) {
    return (
      <main className="min-h-screen bg-white">
        <Header />
        <div className="p-20 text-center text-red-500">Error: Contact Page not found in WordPress.</div>
      </main>
    );
  }

  const $ = cheerio.load(pageData.content);

  // Extract Hero Title
  let heroTitle = $("h1").first().text().trim() || pageData.title || "Contact Us";

  // Extract Sections (e.g. Enquiry, Newsletter)
  const sections: { title: string, paras: string[] }[] = [];
  $("h2").each((i, el) => {
    const title = $(el).text().trim();
    const paras: string[] = [];
    let current = $(el).next();
    while (current.length && !current.is("h2") && !current.is("figure") && !current.is("img")) {
      if (current.is("p")) {
        const html = current.html()?.trim();
        if (html) paras.push(html);
      }
      current = current.next();
    }
    sections.push({ title, paras });
  });

  const enquirySection = sections[0] || { title: "Make an enquiry?", paras: [] };
  const newsletterSection = sections[1] || null;

  // Extract Image
  let imageSrc = pageData.featuredImage?.node?.sourceUrl || $("img").first().attr("src") || "";
  if (imageSrc) imageSrc = imageSrc.replace(/-\d+x\d+(?=\.[a-zA-Z]+$)/, '');

  // Extract Fluent Form HTML
  let fluentFormHtml = "";
  const formElement = $(".fluentform");
  if (formElement.length) {
    fluentFormHtml = formElement.prop("outerHTML") || "";
  }

  return (
    <main className="min-h-screen bg-white selection:bg-[#D71921] selection:text-white flex flex-col">
      <Header />



      {/* Contact Content Section */}
      <section className="w-full flex flex-col lg:flex-row bg-[#f5f5f5] min-h-[700px]">
        {/* Left Side - Form */}
        <div className="w-full lg:w-1/2 p-6 lg:p-12 xl:p-16 flex flex-col relative z-10 bg-[#f5f5f5] justify-center">
          <FadeIn direction="right" delay={0.2} className="w-full max-w-md mx-auto lg:ml-auto lg:mr-10 xl:mr-16">
            <h2 className="text-3xl lg:text-4xl font-sans font-medium text-black mb-6">
              {enquirySection.title}
            </h2>
            
            {enquirySection.paras.map((para, i) => (
              <div key={i} className="text-[#5a6a7e] font-serif text-[14px] leading-relaxed mb-6 wp-parsed-content">
                {parse(para)}
              </div>
            ))}

            <ContactForm 
              formHtml={fluentFormHtml} 
              fields={formFields} 
              title={formTitle} 
              recaptchaEnabled={formSettings?.recaptcha_enabled}
              recaptchaSiteKey={formSettings?.recaptcha_site_key}
              recaptchaType={formSettings?.recaptcha_type}
            />

          </FadeIn>
        </div>

        {/* Right Side - Image and Newsletter */}
        <div className="w-full lg:w-1/2 relative flex flex-col justify-center p-6 lg:p-12 xl:p-16">
          <FadeIn direction="left" delay={0.3} className="w-full max-w-3xl mx-auto flex flex-col h-full justify-center">
            {/* Image */}
            <div className="w-full aspect-[3/2] relative mb-12 shadow-lg rounded-2xl overflow-hidden">
              {imageSrc ? (
                <Image 
                  src={imageSrc} 
                  alt="Contact"
                  fill
                  sizes="(max-width: 1024px) 100vw, 50vw"
                  className="object-cover"
                />
              ) : (
                <div className="w-full h-full flex items-center justify-center text-gray-400 font-sans bg-[#111]">
                  No Image Found
                </div>
              )}
            </div>

            {/* Newsletter Section */}
            {newsletterSection && (
              <div className="w-full">
                <h2 className="text-2xl lg:text-3xl font-sans font-medium text-black mb-4">
                  {newsletterSection.title}
                </h2>
                {newsletterSection.paras.map((para, i) => (
                  <div key={i} className="text-[#5a6a7e] font-serif text-[14px] leading-relaxed mb-4 wp-parsed-content">
                    {parse(para)}
                  </div>
                ))}
              </div>
            )}
          </FadeIn>
        </div>
      </section>
    </main>
  );
}
