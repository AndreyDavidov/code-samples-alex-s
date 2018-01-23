import { Component, OnInit, Input } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';

import { Skill } from '../../classes/skill';
import { SkillService } from "../../services/skill.service";

@Component({
  selector: 'app-skills',
  templateUrl: './skills.component.html',
  styleUrls: ['./skills.component.css']
})
export class SkillsComponent implements OnInit {
  @Input() skills: Skill[];
  @Input() errorMessage: string;
  @Input() actionInProgress: boolean = false;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private skillService: SkillService
  ) {}

  ngOnInit() {
    this.getSkills();
  }

  getSkills(): void {
    this.skillService.list()
      .subscribe(
        skills => this.skills = skills,
        error => this.errorMessage = error.error.message
      );
  }

  addSkill(event): void {
    event.preventDefault();
    this.actionInProgress = true;
    var skill = new Skill();
    skill.title = 'New skill';
    this.skillService.add(skill)
      .subscribe(
        skill => {
          this.skills.push(skill);
          this.router.navigate(['skill/'+skill.id]);
        },
        error => this.errorMessage = error.error.message
      );
  }

}
