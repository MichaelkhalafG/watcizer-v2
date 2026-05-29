import { useEffect, useState } from "react";
import { Card, CardMedia, CardContent, CardActionArea, Typography, Button, Grid, Container } from "@mui/material";

export default function Blogs() {
    const [blogs, setBlogs] = useState([]);
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

    return (
        <Container sx={{ py: 6 }}>
            <Grid container spacing={4}>
                {blogs.map((blog) => {
                    const blogTitle = blog.translations.find((t) => t.locale === 'en')?.title || "No Title Available";
                    const blogContent = blog.translations.find((t) => t.locale === 'en')?.text || "No Content Available";

                    return (
                        <Grid item key={blog.id} xs={12} sm={6} md={4}>
                            <Card sx={{ maxWidth: 345, boxShadow: 3 }}>
                                <CardActionArea onClick={() => window.location.href = `/blog/${blogTitle}`}>
                                    <CardMedia
                                        component="img"
                                        height="200"
                                        image={`https://dash.watchizereg.com/Uploads_Images/Blog/${blog.image}`}
                                        alt={blogTitle}
                                    />
                                    <CardContent>
                                        <Typography gutterBottom variant="h6" component="div">
                                            {blogTitle}
                                        </Typography>
                                        <Typography variant="body2" color="text.secondary" noWrap>
                                            {blogContent}
                                        </Typography>
                                    </CardContent>
                                </CardActionArea>
                                <Button fullWidth variant="contained" color="primary" onClick={() => window.location.href = `/blog/${blogTitle}`}>
                                    Read More
                                </Button>
                            </Card>
                        </Grid>
                    );
                })}
            </Grid>
        </Container>
    );
}
