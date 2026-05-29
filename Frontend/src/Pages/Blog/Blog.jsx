import { useContext, useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { MyContext } from "../../Context/Context";
import { Container, Typography, Box, Grid } from "@mui/material";

function Blog() {
    const { language } = useContext(MyContext);
    const { name } = useParams();
    const [blogs, setBlogs] = useState([]);
    const [blog, setBlog] = useState(null);
    const API_URL = "https://dash.watchizereg.com/api/all_blog";
    const API_CODE = "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0";

    useEffect(() => {
        fetch(API_URL, {
            headers: {
                "Api-Code": API_CODE,
            },
        })
            .then((response) => response.json())
            .then((data) => setBlogs(data))
            .catch(
            // (error) => console.error("Error fetching blogs:", error)
        );
    }, []);

    useEffect(() => {
        const foundBlog = blogs.find((blog) => blog?.translations?.find((t) => t.locale === "en")?.title === name);
        const translation = foundBlog?.translations?.find((t) => t.locale === language);

        if (foundBlog) {
            setBlog({
                title: translation?.title,
                content: translation?.text,
                images: foundBlog?.images || [],
                image: foundBlog?.image || "",
            });
        } else {
            setBlog({ title: "Blog Not Found", content: "No content available.", images: [], image: "" });
        }
    }, [blogs, language, name]);

    if (!blog) return <Typography variant="h6" textAlign="center" mt={4}>Loading...</Typography>;

    return (
        <Container maxWidth="lg">
            {blog.image && (
                <Box display="flex" justifyContent="center" my={4}>
                    <img
                        src={`https://dash.watchizereg.com/Uploads_Images/Blog/${blog.image}`}
                        alt="Blog"
                        style={{
                            width: "100%",
                            maxHeight: "400px",
                            objectFit: "cover",
                            borderRadius: "12px",
                            boxShadow: "0px 4px 10px rgba(0, 0, 0, 0.2)",
                        }}
                    />
                </Box>
            )}

            <Typography variant="h4" fontWeight="bold" textAlign="center" gutterBottom>
                {blog.title}
            </Typography>

            <Typography variant="body1" color="text.secondary" sx={{ textAlign: "justify", lineHeight: 1.8, letterSpacing: "0.5px", mt: 2 }}>
                {blog.content}
            </Typography>

            <Grid container spacing={2} mt={4}>
                {blog.images.map((image, index) => (
                    <Grid item xs={12} sm={6} key={index}>
                        <img
                            src={`https://dash.watchizereg.com/Uploads_Images/Blog_image/${image.image}`}
                            alt="Blog"
                            style={{
                                width: "100%",
                                height: "auto",
                                borderRadius: "10px",
                                boxShadow: "0px 4px 8px rgba(0, 0, 0, 0.1)",
                            }}
                        />
                    </Grid>
                ))}
            </Grid>
        </Container>
    );
}

export default Blog;
