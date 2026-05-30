// import { useContext, useEffect, useState } from "react";
// import { MyContext } from "../Context/Context";
// import FileSaver from "file-saver";

// const BASE_URL = "https://www.watchizereg.com";

// const SitemapGenerator = () => {
//   const { products, tables } = useContext(MyContext);
//   const [sitemapContent, setSitemapContent] = useState("");

//   useEffect(() => {
//     if (!products || !products.length || !tables.categoryTypes || !tables.categoryTypes.length) {
//       console.warn("❌ البيانات غير متاحة بعد، تأجيل إنشاء خريطة الموقع...");
//       return;
//     }

//     console.log("✅ يتم إنشاء خريطة الموقع الآن...");

//     const staticRoutes = [
//       "/",
//       "/products",
//       "/offers",
//       "/cart",
//       "/checkout",
//       "/login",
//       "/register",
//       "/wishlist",
//       "/order-list",
//       "/blogs",
//       "/search",
//       "/404",
//     ];

//     const productRoutes = products.map((p) => `/product/${p.product_title}`).filter(Boolean);
//     const categoryRoutes = tables.categoryTypes.map((c) => `/category/${c.category_type_name}`).filter(Boolean);

//     const allRoutes = [...staticRoutes, ...productRoutes, ...categoryRoutes];

//     const xmlContent = `<?xml version="1.0" encoding="UTF-8"?>
// <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
//   ${allRoutes
//         .map(
//           (route) => `
//     <url>
//       <loc>${BASE_URL}${route}</loc>
//       <changefreq>daily</changefreq>
//       <priority>${route === "/" ? "1.0" : "0.8"}</priority>
//     </url>
//   `
//         )
//         .join("")}
// </urlset>`;

//     console.log("📄 خريطة الموقع تم إنشاؤها:", xmlContent);
//     setSitemapContent(xmlContent);
//   }, [products, tables.categories]);

//   const downloadSitemap = () => {
//     const blob = new Blob([sitemapContent], { type: "application/xml" });
//     FileSaver.saveAs(blob, "sitemap.xml");
//   };

//   return sitemapContent ? (
//     <button onClick={downloadSitemap} className="sitemap-btn">
//       تحميل خريطة الموقع 🗺️
//     </button>
//   ) : (
//     <p>⏳ جاري إنشاء خريطة الموقع...</p>
//   );

// };

// export default SitemapGenerator;
