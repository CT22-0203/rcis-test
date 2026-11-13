-- drop user and database
drop user if exists 'rcis'@'localhost';
drop database if exists rcis;

-- create database
create database rcis;

-- create user
create user 'rcis'@'localhost' identified by 'Changeme0';

-- grant
grant select, insert, update, delete on rcis.* to 'rcis'@'localhost';
grant create, alter, references on rcis.* to 'rcis'@'localhost';
grant create view, show view on rcis.* to 'rcis'@'localhost';
grant create routine, execute on rcis.* to 'rcis'@'localhost';
flush privileges;

use rcis;

-- create tables
create table role (
    id bigint primary key auto_increment,
    role varchar(128),
    description varchar(64)
);

create table person (
    id bigint primary key auto_increment,
    first_name varchar(16),
    middle_name varchar(16),
    last_name varchar(64),
    email varchar(128),
    date_of_birth date,
    gender enum('Male', 'Female'),
    address varchar(255),
    contact_number varchar(16)
);

create table login (
    id bigint primary key auto_increment,
    username varchar(16),
    password varchar(64),
    role_id bigint,
    last_login_date timestamp null,
    person_id bigint not null,
    foreign key (role_id) references role (id),
    foreign key (person_id) references person (id)
);

create table department (
    department_id bigint primary key auto_increment,
    department_name varchar(30) not null unique
);

create table position (
    id bigint primary key auto_increment,
    position_name varchar(100) not null,
    category varchar(20) not null,
    department_id bigint,
    foreign key (department_id) references department (department_id)
);

create table personnel (
    id bigint primary key auto_increment,
    person_id bigint not null unique,
    employee_id varchar(30) unique,
    foreign key (person_id) references person (id)
);

create table personnel_position (
    personnel_id bigint not null,
    position_id bigint not null,
    department_id bigint,
    primary key (personnel_id, position_id),
    foreign key (personnel_id) references personnel (id),
    foreign key (position_id) references position (id),
    foreign key (department_id) references department (department_id)
);

create table section (
    id bigint primary key auto_increment,
    code varchar(30) not null,
    name varchar(100) not null,
    department_id bigint not null,
    year_level varchar(30) not null,
    semester varchar(30),
    academic_year varchar(20) not null,
    adviser_id bigint,
    foreign key (department_id) references department (department_id),
    foreign key (adviser_id) references personnel (id)
);

create table student (
    id bigint primary key auto_increment,
    person_id bigint not null unique,
    student_number varchar(30) not null unique,
    foreign key (person_id) references person (id)
);

create table student_section (
    id bigint primary key auto_increment,
    student_id bigint not null,
    section_id bigint not null,
    enrolled_date timestamp not null default current_timestamp,
    foreign key (student_id) references student (id),
    foreign key (section_id) references section (id)
);

-- create test data
insert into role (role, description) values
('Clinic Staff', 'The account for a clinic staff');

insert into person (first_name, middle_name, last_name, email, date_of_birth, gender, address, contact_number) values
('John', 'Michael', 'Doe', 'john.doe@example.com', '2015-05-15', 'Male', '123 Rizal Street, San Pedro, Laguna', '09191223222');

insert into person (first_name, middle_name, last_name, email, date_of_birth, gender, address, contact_number) values
('Jowan', 'Carl', 'Quirona', 'jowan123@rocs.edu.ph', '2005-03-14', 'Male', 'Langkaan', '09171234501'),
('Allison', null, 'Reyes', 'allison23@rocs.edu.ph', '2004-08-22', 'Female', 'Silang', '09171234502'),
('Ana', 'Ramos', 'Corpuz', 'anaramos@rocs.edu.ph', '1990-05-17', 'Female', 'Tagaytay', '09171234504'),
('Carlos', 'Diaz', 'Gona', 'carlosyolo@rocs.edu.ph', '1985-11-02', 'Male', 'General Trias', '09171234505'),
('Elena', null, 'Bautista', 'elenabautista@rocs.edu.ph', '1980-02-11', 'Female', 'Lalaan Dos', '09171234507'),
('Roberto', null, 'Aquino', 'robertoaquino@rocs.edu.ph', '1978-09-25', 'Male', 'Manila', '09171234508'),
('Grace', 'Mae', 'Villar', 'villarmae@rocs.edu.ph', '1988-04-03', 'Female', 'Alfonso, Tagaytay', '09171234509'),
('Antonio', null, 'Ferrer', 'ferrerantonio@rocs.edu.ph', '1975-06-12', 'Male', 'Silang', '09171234510');

insert into login (username, password, role_id, last_login_date, person_id) values
('staff', '1234', 1, null, 1);

insert into department (department_name) values
('Junior High School'),
('Senior High School'),
('College');

insert into position (position_name, category, department_id) values
('Nurse', 'Clinic Staff', null),
('Doctor', 'Clinic Staff', null),
('College Teacher', 'RC Employee', 3),
('Adviser', 'RC Employee', null),
('Cashier', 'RC Employee', null),
('Maintenance Personnel', 'Community', null);

insert into personnel (person_id, employee_id) values
(4, 'EMP-001'),
(5, 'EMP-002'),
(6, 'EMP-003'),
(7, 'EMP-004'),
(8, 'EMP-005'),
(9, null);

insert into personnel_position (personnel_id, position_id, department_id) values
(1, 1, null),
(2, 2, null),
(3, 3, 3),
(3, 4, 3),
(4, 3, 3),
(4, 4, 3),
(5, 5, null),
(6, 6, null);

insert into section (code, name, department_id, year_level, semester, academic_year, adviser_id) values
('401', 'BSIT 401', 3, '2nd Year', '2nd Semester', '2026-2027', 3),
('601', 'BSIT 601', 3, '3rd Year', '2nd Semester', '2026-2027', 4);

insert into student (person_id, student_number) values
(2, 'CT22-0166'),
(3, 'CT23-0987');

insert into student_section (student_id, section_id) values
(1, 1),
(2, 2);
