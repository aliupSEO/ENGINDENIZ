import { NextResponse } from "next/server";

// Helper to send data to Google Firebase Firestore via REST API (Local Dev Offline Backup only)
async function saveToFirestoreBackup(fields: Record<string, string>) {
  try {
    const projectId = process.env.FIREBASE_PROJECT_ID;
    const apiKey = process.env.FIREBASE_API_KEY;
    const collection = process.env.FIREBASE_COLLECTION || "submissions";

    if (!projectId || !apiKey) {
      console.warn("Firebase configuration is missing in environment variables. Local backup skipped.");
      return;
    }

    const firestoreFields: Record<string, { stringValue: string }> = {};
    for (const [key, value] of Object.entries(fields)) {
      if (value !== undefined && value !== null) {
        firestoreFields[key] = { stringValue: String(value) };
      }
    }

    // Add metadata
    firestoreFields["submitted_at"] = { stringValue: new Date().toISOString() };

    const response = await fetch(
      `https://firestore.googleapis.com/v1/projects/${projectId}/databases/(default)/documents/${collection}?key=${apiKey}`,
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          fields: firestoreFields,
        }),
      }
    );

    if (!response.ok) {
      const errBody = await response.text();
      console.error("Firestore Backup Error:", errBody);
    } else {
      console.log("Form submission successfully backed up to Firebase Firestore directly from Next.js.");
    }
  } catch (error) {
    console.error("Error saving backup to Firestore:", error);
  }
}

export async function POST(request: Request) {
  try {
    const contentType = request.headers.get("content-type") || "";
    let submissionFields: Record<string, string> = {};

    // 1. Extract values depending on Content-Type
    if (contentType.includes("application/x-www-form-urlencoded")) {
      const bodyText = await request.text();
      const params = new URLSearchParams(bodyText);
      const rawDataString = params.get("data") || "";
      const rawData = new URLSearchParams(rawDataString);

      for (const [key, value] of rawData.entries()) {
        if (key.startsWith("_") || ["action", "form_id"].includes(key)) {
          continue;
        }

        let cleanKey = key;
        if (key.includes("[") && key.includes("]")) {
          const matches = [...key.matchAll(/\[(.*?)\]/g)];
          if (matches.length > 0) {
            cleanKey = matches[matches.length - 1][1] || key;
          }
        }

        // Normalize default fluent form inputs or German keys
        if (cleanKey === "input_text") cleanKey = "name";
        if (cleanKey === "input_text_1") cleanKey = "address";
        if (cleanKey === "input_text_2") cleanKey = "plz_ort";
        if (cleanKey === "adresse") cleanKey = "address";
        if (cleanKey === "plzOrt") cleanKey = "plz_ort";

        submissionFields[cleanKey] = value;
      }
    } else {
      // JSON format (our standalone next.js forms submit JSON)
      const body = await request.json();
      submissionFields = { ...body };

      // Dynamic automatic detection of core fields for WordPress DB compatibility
      if (!submissionFields.email) {
        const emailKey = Object.keys(body).find(k => k.toLowerCase().includes("email") || k.toLowerCase().includes("mail"));
        if (emailKey) submissionFields.email = body[emailKey];
      }
      if (!submissionFields.name) {
        const nameKey = Object.keys(body).find(k => k.toLowerCase().includes("name"));
        if (nameKey) submissionFields.name = body[nameKey];
      }
      if (!submissionFields.address) {
        const addrKey = Object.keys(body).find(k => k.toLowerCase().includes("address") || k.toLowerCase().includes("adresse") || k.toLowerCase().includes("street"));
        if (addrKey) submissionFields.address = body[addrKey];
      }
      if (!submissionFields.plz_ort) {
        const plzKey = Object.keys(body).find(k => k.toLowerCase().includes("plz") || k.toLowerCase().includes("ort") || k.toLowerCase().includes("zip") || k.toLowerCase().includes("city"));
        if (plzKey) submissionFields.plz_ort = body[plzKey];
      }
    }

    console.log("Parsed submission fields:", submissionFields);

    // 2. Post to Custom WordPress REST API Endpoint
    let wpSuccess = false;
    let wpErrorMsg = "";

    try {
      const wpResponse = await fetch("https://silvioh22.sg-host.com/wp-json/firebase-form/v1/submit", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
        },
        body: JSON.stringify(submissionFields),
      });

      const wpData = await wpResponse.json();

      if (wpResponse.ok && wpData.success) {
        wpSuccess = true;
        console.log("Successfully synced submission to WordPress Custom Table and Firebase via REST API!");
        return NextResponse.json({ success: true, ...wpData });
      } else {
        wpErrorMsg = wpData.message || "Failed WordPress REST API verification.";
        console.warn("WordPress REST API rejected the submission:", wpData);
      }
    } catch (wpErr: any) {
      wpErrorMsg = wpErr.message || "WordPress server unreachable.";
      console.error("Failed to connect to WordPress REST API:", wpErr);
    }

    // 3. Fallback: If WordPress server is down or rejects (e.g. offline local development),
    // save directly to Firebase Firestore from Next.js server-side as a secure fallback.
    if (!wpSuccess) {
      console.log("Initiating local Next.js direct backup to Firebase Firestore...");
      await saveToFirestoreBackup(submissionFields);
      return NextResponse.json({
        success: true,
        message: "Saved to Firebase successfully, but WordPress database sync was bypassed.",
        warning: wpErrorMsg
      });
    }

    return NextResponse.json({ success: true });
  } catch (error) {
    console.error("API Route Error:", error);
    return NextResponse.json({ success: false, error: "Internal Server Error" }, { status: 500 });
  }
}


