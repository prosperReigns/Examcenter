import asyncio

from app.core.roles import Roles
from sqlalchemy import select
from app.auth.security import get_password_hash
from app.database.session import SessionLocal
from app.models.admin import Admin

SUPER_ADMIN ={
    "full_name":"system administrator",
    "email": "admin@examcenter.com",
    "password": "superadmin123",
    "role": Roles.SUPER_ADMIN
}

def seed():
    db = SessionLocal()
    try:
        result = db.execute(select(Admin).where(Admin.email == SUPER_ADMIN["email"]))

        admin = result.scalar_one_or_none()

        if admin:
            print("super admin already exists")
            return 
        admin = Admin(full_name=SUPER_ADMIN["full_name"],
                    email=SUPER_ADMIN["email"],
                    password_hash=get_password_hash(SUPER_ADMIN["password"]),
                    role=SUPER_ADMIN["role"],
                    is_active=True)
        
        db.add(admin)
        db.commit()

        print("super Admin created successfully")
        print(f"email: {SUPER_ADMIN['email']}")
        print(f"password: {SUPER_ADMIN['password']}")
    finally:
        db.close()

if __name__ == "__main__":
    seed()














