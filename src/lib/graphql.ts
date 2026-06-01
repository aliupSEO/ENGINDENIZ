export async function fetchGraphQL(query: string, variables = {}) {
  const endpoint = process.env.NEXT_PUBLIC_WORDPRESS_GRAPHQL_URL || "https://silvioh22.sg-host.com/graphql";

  try {
    const res = await fetch(endpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        query,
        variables,
      }),
      // Reduced ISR caching from 15s to 1s to make WordPress changes appear instantly
      // while still providing a tiny buffer against SiteGround Anti-Bot limits
      next: { revalidate: 1 },
    });

    const contentType = res.headers.get("content-type");
    if (!contentType || !contentType.includes("application/json")) {
      const text = await res.text();
      console.error("Non-JSON response received:", text.substring(0, 500));
      throw new Error(`Expected JSON but got HTML. Status: ${res.status}`);
    }

    const json = await res.json();
    
    if (json.errors) {
      console.error(json.errors);
      throw new Error("Failed to fetch API");
    }

    // Recursively clean up " - Addidas" from SEO titles and descriptions
    const cleanAddidas = (obj: any) => {
      if (!obj || typeof obj !== "object") return;
      
      if (obj.seo && typeof obj.seo.title === "string") {
        obj.seo.title = obj.seo.title.replace(/\s*-\s*Addidas/gi, "").trim();
      }
      if (obj.seo && obj.seo.openGraph && typeof obj.seo.openGraph.title === "string") {
        obj.seo.openGraph.title = obj.seo.openGraph.title.replace(/\s*-\s*Addidas/gi, "").trim();
      }

      Object.values(obj).forEach((val) => {
        if (val && typeof val === "object") {
          cleanAddidas(val);
        }
      });
    };

    cleanAddidas(json.data);

    return json.data;
  } catch (error) {
    console.error(error);
    throw new Error("Network error fetching GraphQL");
  }
}

export async function getLawFirmPage() {
  const data = await fetchGraphQL(`
    query {
      pages(where: {search: "law firm"}) {
        nodes {
          id
          title
          slug
          content
          featuredImage {
            node {
              sourceUrl
            }
          }
          seo {
            title
            description
            focusKeywords
            canonicalUrl
            robots
            openGraph {
              title
              description
              image {
                secureUrl
              }
            }
          }
        }
      }
    }
  `);

  return data?.pages?.nodes[0] || null;
}

export async function getFormSettings() {
  try {
    const endpoint = "https://silvioh22.sg-host.com/wp-json/firebase-form/v1/settings";
    const res = await fetch(endpoint, {
      method: "GET",
      headers: {
        "Accept": "application/json",
      },
      next: { revalidate: 1 },
    });
    if (!res.ok) return null;
    const data = await res.json();
    return data;
  } catch (error) {
    console.error("Error fetching form settings:", error);
    return null;
  }
}

export async function getTeamPage() {
  const data = await fetchGraphQL(`
    query {
      pages(where: {search: "Team"}) {
        nodes {
          id
          title
          slug
          content
          featuredImage {
            node {
              sourceUrl
            }
          }
          seo {
            title
            description
            focusKeywords
            canonicalUrl
            robots
            openGraph {
              title
              description
              image {
                secureUrl
              }
            }
          }
        }
      }
    }
  `);

  return data?.pages?.nodes[0] || null;
}

export async function getContactPage() {
  const data = await fetchGraphQL(`
    query {
      pages(where: {search: "Contact"}) {
        nodes {
          id
          title
          slug
          content
          featuredImage {
            node {
              sourceUrl
            }
          }
          seo {
            title
            description
            focusKeywords
            canonicalUrl
            robots
            openGraph {
              title
              description
              image {
                secureUrl
              }
            }
          }
        }
      }
    }
  `);

  return data?.pages?.nodes[0] || null;
}

export async function getRealEstateLawPage() {
  const data = await fetchGraphQL(`
    query {
      pages(where: {search: "Real estate law"}) {
        nodes {
          id
          title
          slug
          content
          featuredImage {
            node {
              sourceUrl
            }
          }
          seo {
            title
            description
            focusKeywords
            canonicalUrl
            robots
            openGraph {
              title
              description
              image {
                secureUrl
              }
            }
          }
        }
      }
    }
  `);

  return data?.pages?.nodes[0] || null;
}

export async function getAnniversaryPage() {
  const data = await fetchGraphQL(`
    query {
      pages(where: {search: "Anniversary"}) {
        nodes {
          id
          title
          slug
          content
          featuredImage {
            node {
              sourceUrl
            }
          }
          seo {
            title
            description
            focusKeywords
            canonicalUrl
            robots
            openGraph {
              title
              description
              image {
                secureUrl
              }
            }
          }
        }
      }
    }
  `);

  return data?.pages?.nodes[0] || null;
}

export async function getPanoramaPage() {
  const data = await fetchGraphQL(`
    query {
      pages(where: {search: "Panaroma"}) {
        nodes {
          id
          title
          slug
          content
          featuredImage {
            node {
              sourceUrl
            }
          }
          seo {
            title
            description
            focusKeywords
            canonicalUrl
            robots
            openGraph {
              title
              description
              image {
                secureUrl
              }
            }
          }
        }
      }
    }
  `);

  return data?.pages?.nodes[0] || null;
}

export async function getImprintPage() {
  const data = await fetchGraphQL(`
    query {
      pages(where: {name: "imprint"}) {
        nodes {
          id
          title
          slug
          content
          seo {
            title
            description
            canonicalUrl
          }
        }
      }
    }
  `);
  return data?.pages?.nodes[0] || null;
}

export async function getPrivacyPolicyPage() {
  const data = await fetchGraphQL(`
    query {
      pages(where: {name: "privacy-policy"}) {
        nodes {
          id
          title
          slug
          content
          seo {
            title
            description
            canonicalUrl
          }
        }
      }
    }
  `);
  return data?.pages?.nodes[0] || null;
}

export async function getTermsAndConditionsPage() {
  const data = await fetchGraphQL(`
    query {
      pages(where: {name: "terms-and-conditions"}) {
        nodes {
          id
          title
          slug
          content
          seo {
            title
            description
            canonicalUrl
          }
        }
      }
    }
  `);
  return data?.pages?.nodes[0] || null;
}

export async function getPageBySlug(slug: string) {
  const data = await fetchGraphQL(`
    query GetPageBySlug($name: String!) {
      pages(where: {name: $name}) {
        nodes {
          id
          title
          slug
          content
          featuredImage {
            node {
              sourceUrl
            }
          }
          seo {
            title
            description
            canonicalUrl
            openGraph {
              title
              description
              image {
                secureUrl
              }
            }
          }
        }
      }
    }
  `, { name: slug });
  
  return data?.pages?.nodes[0] || null;
}
