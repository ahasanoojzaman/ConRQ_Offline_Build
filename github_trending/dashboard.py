import streamlit as st
import sqlite3
import pandas as pd

DB_NAME = "trending.db"

def load_data():
    conn = sqlite3.connect(DB_NAME)
    query = "SELECT * FROM trending_repos"
    df = pd.read_sql(query, conn)
    conn.close()
    return df

st.set_page_config(page_title="GitHub Trending Dashboard", layout="wide")
st.title("📈 GitHub Trending Repositories Dashboard")
st.markdown("Visualizing the top trending repositories dynamically updated by our scraping pipeline.")

try:
    df = load_data()
    if df.empty:
        st.warning("No data found in the database. Please run the scraper (`python scraper.py`) first.")
    else:
        st.write(f"**Total Records:** {len(df)}")
        
        # Data Preview
        st.subheader("Data Preview (Recent Scrapes)")
        st.dataframe(df.sort_values(by=["scrape_date", "stars"], ascending=[False, False]).head(20), use_container_width=True)
        
        col1, col2 = st.columns(2)
        
        with col1:
            st.subheader("Popular Languages in Trends")
            lang_counts = df[df['language'] != 'Unknown']['language'].value_counts()
            if not lang_counts.empty:
                st.bar_chart(lang_counts)
            else:
                st.info("No language data available.")
            
        with col2:
            st.subheader("Top Repositories by Stars (Recorded)")
            top_stars = df.nlargest(10, 'stars')
            if not top_stars.empty:
                st.bar_chart(top_stars.set_index('repo_name')['stars'])
            else:
                st.info("No stars data available.")
            
except Exception as e:
    st.error(f"Error loading data: {e}")
