"use client";

import React, { useState, useRef } from "react";

interface FormField {
  id: string;
  label: string;
  type: string;
  placeholder?: string;
  required?: boolean;
}

export default function ContactForm({ 
  formHtml, 
  fields, 
  title 
}: { 
  formHtml?: string; 
  fields?: FormField[]; 
  title?: string; 
}) {
  const [formData, setFormData] = useState({
    name: "",
    adresse: "",
    plzOrt: "",
    email: "",
  });

  const [dynamicFormData, setDynamicFormData] = useState<Record<string, string>>({});
  const [status, setStatus] = useState<"idle" | "loading" | "success" | "error">("idle");
  const formRef = useRef<HTMLDivElement>(null);

  const handleInputChange = (fieldId: string, value: string) => {
    setDynamicFormData(prev => ({
      ...prev,
      [fieldId]: value
    }));
  };

  const handleDynamicFieldsSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setStatus("loading");
    
    try {
      const response = await fetch("/api/contact", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(dynamicFormData)
      });

      if (response.ok) {
        setStatus("success");
        // Clear inputs
        const cleared: Record<string, string> = {};
        if (fields) {
          fields.forEach(f => { cleared[f.id] = ""; });
        }
        setDynamicFormData(cleared);
      } else {
        setStatus("error");
      }
    } catch (error) {
      console.error(error);
      setStatus("error");
    }
  };

  const handleDynamicSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setStatus("loading");

    const form = e.target as HTMLFormElement;
    const data = new FormData(form);
    
    // Serialize form data exactly as Fluent Forms expects
    const serializedData = new URLSearchParams(data as any).toString();
    
    const submitData = new URLSearchParams();
    submitData.append("action", "fluentform_submit");
    submitData.append("form_id", data.get("form_id") as string || "1");
    submitData.append("data", serializedData);

    try {
      const response = await fetch("/api/contact", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: submitData.toString()
      });

      const result = await response.json();
      if (result.success || result.insert_id) {
        setStatus("success");
        form.reset();
      } else {
        console.error(result.error);
        setStatus("error");
      }
    } catch (error) {
      console.error(error);
      setStatus("error");
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setStatus("loading");
    
    try {
      const response = await fetch("/api/contact", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          _subject: "New Registration / Enquiry",
          Name: formData.name,
          Adresse: formData.adresse,
          "PLZ / Ort": formData.plzOrt,
          "E-Mail": formData.email,
        })
      });

      if (response.ok) {
        setStatus("success");
        setFormData({ name: "", adresse: "", plzOrt: "", email: "" });
      } else {
        setStatus("error");
      }
    } catch (error) {
      console.error(error);
      setStatus("error");
    }
  };

  if (fields && fields.length > 0) {
    const submitField = fields.find(f => f.type === "custom_submit");
    const submitButtonText = submitField?.label || "Register";

    return (
      <form onSubmit={handleDynamicFieldsSubmit} className="flex flex-col space-y-4 font-sans w-full mb-8">
        {fields.map((field) => {
          const fid = field.id;
          const flabel = field.label;
          const ftype = field.type;
          const fplaceholder = field.placeholder || "";
          const frequired = !!field.required;
          
          if (ftype === "custom_submit") {
            return null;
          }
          
          return (
            <div key={fid} className="flex flex-col space-y-1">
              <label className="text-xs font-semibold text-gray-500 uppercase tracking-wider pl-1">{flabel}</label>
              <input 
                type={ftype} 
                placeholder={fplaceholder} 
                value={dynamicFormData[fid] || ""}
                onChange={(e) => handleInputChange(fid, e.target.value)}
                className="w-full px-4 py-3 bg-white border border-transparent rounded-none text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-gray-300 focus:border-gray-300 transition-all text-[14px]"
                required={frequired}
              />
            </div>
          );
        })}
        
        <div className="pt-2 flex flex-col space-y-3">
          <button 
            type="submit" 
            disabled={status === "loading"}
            className="bg-black text-white px-8 py-3 rounded-full font-sans font-medium hover:bg-[#d71921] transition-colors text-[14px] inline-flex items-center justify-center cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed w-full"
          >
            {status === "loading" ? "Sending..." : submitButtonText}
          </button>
          
          {status === "success" && (
            <p className="text-green-600 font-sans text-sm">Registration sent successfully!</p>
          )}
          {status === "error" && (
            <p className="text-red-500 font-sans text-sm">Something went wrong. Please try again.</p>
          )}
        </div>
      </form>
    );
  }

  if (formHtml) {
    return (
      <div className="w-full mb-8 font-sans" ref={formRef}>
        <style dangerouslySetInnerHTML={{ __html: `
          .fluentform-wrapper .ff-el-group {
            margin-bottom: 12px;
          }
          .fluentform-wrapper input,
          .fluentform-wrapper textarea,
          .fluentform-wrapper select {
            width: 100%;
            padding: 12px 16px;
            background-color: #ffffff;
            border: none;
            border-radius: 0;
            color: #1f2937;
            font-size: 14px;
            transition: all 0.2s ease-in-out;
            outline: none;
          }
          .fluentform-wrapper input::placeholder,
          .fluentform-wrapper textarea::placeholder {
            color: #6b7280;
          }
          .fluentform-wrapper input:focus,
          .fluentform-wrapper textarea:focus {
            box-shadow: 0 0 0 1px #d1d5db;
          }
          .fluentform-wrapper .ff_submit_btn_wrapper {
            margin-top: 16px;
            display: flex;
            flex-direction: column;
          }
          .fluentform-wrapper button[type="submit"] {
            background-color: #000000;
            color: #ffffff;
            padding: 12px 32px;
            border-radius: 9999px; /* pill shape */
            font-family: inherit;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: background-color 0.2s;
            width: 100%;
          }
          .fluentform-wrapper button[type="submit"]:hover {
            background-color: #d71921;
          }
          .fluentform-wrapper button[type="submit"]:disabled {
            opacity: 0.5;
            cursor: not-allowed;
          }
          .fluentform-wrapper .ff-errors-in-stack,
          .fluentform-wrapper .text-danger {
            color: #ef4444;
            font-size: 12px;
            margin-top: 4px;
          }
          /* Hide legends and extra visual clutter from standard WP forms */
          .fluentform-wrapper legend {
            display: none !important;
          }
        `}} />
        <div 
          className="fluentform-wrapper"
          onSubmit={handleDynamicSubmit}
          dangerouslySetInnerHTML={{ __html: formHtml }}
        />
        
        <div className="pt-4 flex flex-col space-y-3">
          {status === "loading" && (
            <p className="text-gray-500 font-sans text-sm">Sending...</p>
          )}
          {status === "success" && (
            <p className="text-green-600 font-sans text-sm">Registration sent successfully!</p>
          )}
          {status === "error" && (
            <p className="text-red-500 font-sans text-sm">Something went wrong. Please check required fields and try again.</p>
          )}
        </div>
      </div>
    );
  }

  // Fallback if no form HTML
  return (
    <form onSubmit={handleSubmit} className="flex flex-col space-y-3 font-sans w-full mb-8">
      <input 
        type="text" 
        placeholder="Name" 
        value={formData.name}
        onChange={(e) => setFormData({ ...formData, name: e.target.value })}
        className="w-full px-4 py-3 bg-white rounded-none text-gray-800 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all text-[14px]"
        required
      />
      <input 
        type="text" 
        placeholder="Adresse" 
        value={formData.adresse}
        onChange={(e) => setFormData({ ...formData, adresse: e.target.value })}
        className="w-full px-4 py-3 bg-white rounded-none text-gray-800 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all text-[14px]"
        required
      />
      <input 
        type="text" 
        placeholder="PLZ / Ort" 
        value={formData.plzOrt}
        onChange={(e) => setFormData({ ...formData, plzOrt: e.target.value })}
        className="w-full px-4 py-3 bg-white rounded-none text-gray-800 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all text-[14px]"
        required
      />
      <input 
        type="email" 
        placeholder="E-Mail" 
        value={formData.email}
        onChange={(e) => setFormData({ ...formData, email: e.target.value })}
        className="w-full px-4 py-3 bg-white rounded-none text-gray-800 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all text-[14px]"
        required
      />
      
      <div className="pt-4 flex flex-col space-y-3">
        <button 
          type="submit" 
          disabled={status === "loading"}
          className="bg-black text-white px-8 py-3 rounded-full font-sans font-medium hover:bg-[#d71921] transition-colors text-[14px] inline-flex items-center justify-center cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed w-full"
        >
          {status === "loading" ? "Sending..." : "Register"}
        </button>
        
        {status === "success" && (
          <p className="text-green-600 font-sans text-sm">Registration sent successfully!</p>
        )}
        {status === "error" && (
          <p className="text-red-500 font-sans text-sm">Something went wrong. Please try again.</p>
        )}
      </div>
    </form>
  );
}
